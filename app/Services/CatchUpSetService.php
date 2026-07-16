<?php

namespace App\Services;

use App\Models\GuidedAttemptQuestion;
use App\Models\Question;
use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Models\SyllabusTopic;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetPurpose;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CatchUpSetService
{
    public function __construct(
        private McqImportService $mcqImport,
        private FillBlankImportService $fillBlankImport,
        private PracticeSetService $practiceSetService,
        private SetAssignmentService $assignmentService,
    ) {}

    /**
     * Students with weak guided-practice items on this topic (not yet covered by a catch-up set).
     *
     * @return list<array<string, mixed>>
     */
    public function weakStudentsForTopic(SyllabusTopic $topic): array
    {
        $grouped = $this->weakItemsByEnrollment($topic);

        return $grouped->map(function (Collection $items, int $enrollmentId) {
            $first = $items->first();

            return [
                'student_enrollment_id' => $enrollmentId,
                'student_id' => $first['student_id'],
                'student_name' => $first['student_name'],
                'weak_count' => $items->count(),
                'items' => $items->values()->all(),
            ];
        })->sortBy('student_name')->values()->all();
    }

    /**
     * @param  list<int>  $enrollmentIds
     */
    public function buildBatchPrompt(SyllabusTopic $topic, array $enrollmentIds): string
    {
        $topic->loadMissing([
            'chapter.syllabusVersion.board',
            'chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.academicYear',
        ]);

        $grouped = $this->weakItemsByEnrollment($topic)
            ->only(array_map('intval', $enrollmentIds))
            ->filter(fn (Collection $items) => $items->isNotEmpty());

        if ($grouped->isEmpty()) {
            throw new InvalidArgumentException('No pending weak sums for the selected students on this topic.');
        }

        $chapter = $topic->chapter;
        $version = $chapter?->syllabusVersion;
        $context = collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            $chapter ? "Chapter: {$chapter->chapter_number} — {$chapter->name}" : null,
            "Topic: {$topic->name}",
        ])->filter()->implode("\n");

        $studentBlocks = [];

        foreach ($grouped as $enrollmentId => $items) {
            $name = $items->first()['student_name'];
            $lines = [];

            foreach ($items->values() as $index => $item) {
                $n = $index + 1;
                $type = $item['type'] === Question::TYPE_FILL_IN_BLANK ? 'fill_in_blank' : 'mcq';
                $lines[] = "Source #{$n} (source_question_id={$item['question_id']}, type={$type}, set={$item['set_code']}, reason={$item['reason']}):";
                $lines[] = $item['question_text'];

                if ($type === 'mcq' && ! empty($item['options'])) {
                    foreach ($item['options'] as $optIndex => $optText) {
                        $letter = chr(65 + $optIndex);
                        $lines[] = "  {$letter}. {$optText}";
                    }
                }

                if ($type === 'fill_in_blank' && ! empty($item['correct_answer'])) {
                    $lines[] = "  (original blank answer format: {$item['answer_format']}; do NOT copy this answer — invent a similar sum)";
                }

                $lines[] = '';
            }

            $studentBlocks[] = "=== STUDENT: {$name} | student_enrollment_id={$enrollmentId} | variants needed: {$items->count()} ===\n"
                .implode("\n", $lines);
        }

        $body = implode("\n", $studentBlocks);

        return <<<PROMPT
Create catch-up practice variants for students who struggled on this topic. Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Goal:
- For EACH source question below, create ONE similar question with different numbers / variables / wording.
- Keep the same skill and difficulty feel. Do not make it much harder or much easier.
- Keep the same type as the source (mcq stays mcq; fill_in_blank stays fill_in_blank).
- Do NOT reuse the same numbers as the source.
- Include method_hint (theory only — no final answer) and explanation (teacher working with answer).

{$body}

JSON format (must include every student_enrollment_id and every source_question_id listed above):
{
  "students": [
    {
      "student_enrollment_id": 12,
      "variants": [
        {
          "source_question_id": 101,
          "type": "mcq",
          "question": "...",
          "options": ["A text", "B text", "C text", "D text"],
          "correct_index": 0,
          "method_hint": "Theory only",
          "explanation": "Teacher working",
          "difficulty": "Medium"
        },
        {
          "source_question_id": 102,
          "type": "fill_in_blank",
          "question": "... = ____",
          "answer_format": "integer",
          "correct_answer": "4",
          "method_hint": "Theory only",
          "explanation": "Teacher working",
          "difficulty": "Easy"
        }
      ]
    }
  ]
}
PROMPT;
    }

    /**
     * Parse Cursor JSON, create one published catch-up set per student, assign each student.
     *
     * @param  list<int>  $enrollmentIds
     * @return array{created: list<array<string, mixed>>, prompt_students: int}
     */
    public function importAndCreate(
        SyllabusTopic $topic,
        string $json,
        array $enrollmentIds,
        User $assigner,
        string $dueDate,
    ): array {
        $allowed = collect($enrollmentIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $payload = $this->parseBatchJson($json);

        $weakByEnrollment = $this->weakItemsByEnrollment($topic)
            ->only($allowed->all());

        $created = [];

        DB::transaction(function () use ($topic, $payload, $weakByEnrollment, $assigner, $dueDate, &$created) {
            foreach ($payload as $studentBlock) {
                $enrollmentId = (int) $studentBlock['student_enrollment_id'];
                $weakItems = $weakByEnrollment->get($enrollmentId);

                if (! $weakItems || $weakItems->isEmpty()) {
                    continue;
                }

                $enrollment = StudentEnrollment::query()->with('student')->find($enrollmentId);
                if (! $enrollment) {
                    continue;
                }

                $allowedSourceIds = $weakItems->pluck('question_id')->map(fn ($id) => (int) $id)->all();
                $variants = collect($studentBlock['variants'])
                    ->filter(fn (array $v) => in_array((int) ($v['source_question_id'] ?? 0), $allowedSourceIds, true))
                    ->values();

                if ($variants->isEmpty()) {
                    continue;
                }

                $questionIds = [];
                $sourceIds = [];

                foreach ($variants as $variant) {
                    $sourceId = (int) $variant['source_question_id'];
                    $type = $variant['type'] ?? Question::TYPE_MCQ;

                    if ($type === Question::TYPE_FILL_IN_BLANK) {
                        $rows = $this->fillBlankImport->parseJson(json_encode([
                            'questions' => [[
                                'question' => $variant['question'] ?? $variant['question_text'] ?? '',
                                'answer_format' => $variant['answer_format'] ?? 'integer',
                                'correct_answer' => $variant['correct_answer'] ?? '',
                                'decimal_places' => $variant['decimal_places'] ?? null,
                                'method_hint' => $variant['method_hint'] ?? null,
                                'explanation' => $variant['explanation'] ?? null,
                                'difficulty' => $variant['difficulty'] ?? null,
                            ]],
                        ]));
                        $saved = $this->fillBlankImport->saveRows(
                            $topic,
                            $rows,
                            $assigner->id,
                            Question::SOURCE_AI,
                            QuestionBankPurpose::PRACTICE_SET,
                        );
                    } else {
                        $rows = $this->mcqImport->parseJson(json_encode([
                            'questions' => [[
                                'question' => $variant['question'] ?? $variant['question_text'] ?? '',
                                'options' => $variant['options'] ?? [],
                                'correct_index' => $variant['correct_index'] ?? 0,
                                'method_hint' => $variant['method_hint'] ?? null,
                                'explanation' => $variant['explanation'] ?? null,
                                'difficulty' => $variant['difficulty'] ?? null,
                            ]],
                        ]));
                        $saved = $this->mcqImport->saveRows(
                            $topic,
                            $rows,
                            $assigner->id,
                            Question::SOURCE_AI,
                            QuestionBankPurpose::PRACTICE_SET,
                        );
                    }

                    if ($saved === []) {
                        continue;
                    }

                    $questionIds[] = $saved[0]->id;
                    $sourceIds[] = $sourceId;
                }

                if ($questionIds === []) {
                    continue;
                }

                $parentWorksheetId = $weakItems
                    ->groupBy('worksheet_id')
                    ->sortByDesc(fn (Collection $g) => $g->count())
                    ->keys()
                    ->first();
                $parentCode = (string) ($weakItems->firstWhere('worksheet_id', $parentWorksheetId)['set_code']
                    ?? $weakItems->first()['set_code']
                    ?? 'SET');
                $parentTier = (string) ($weakItems->firstWhere('worksheet_id', $parentWorksheetId)['tier']
                    ?? PracticeSetTier::STARTER);

                $setCode = $this->nextCatchUpCode($parentCode, $enrollmentId, $topic->id);
                $setNumber = $this->practiceSetService->nextSetNumber($topic->id);
                $studentName = $enrollment->student?->name ?? 'Student';

                $worksheet = Worksheet::create([
                    'title' => "{$setCode} — Catch-up for {$studentName} (".count($questionIds).' sums)',
                    'set_number' => $setNumber,
                    'set_code' => $setCode,
                    'tier' => in_array($parentTier, PracticeSetTier::topicTiers(), true)
                        ? $parentTier
                        : PracticeSetTier::STARTER,
                    'scope' => PracticeSetScope::TOPIC,
                    'syllabus_topic_id' => $topic->id,
                    'status' => Worksheet::STATUS_PUBLISHED,
                    'notes' => "Catch-up set from weak guided practice on topic {$topic->name}",
                    'created_by' => $assigner->id,
                    'purpose' => WorksheetPurpose::CATCH_UP,
                    'catch_up_parent_worksheet_id' => $parentWorksheetId,
                    'catch_up_for_enrollment_id' => $enrollmentId,
                    'catch_up_source_question_ids' => array_values(array_unique($sourceIds)),
                ]);

                foreach ($questionIds as $index => $questionId) {
                    $worksheet->questions()->attach($questionId, ['sort_order' => $index + 1]);
                }

                $assignment = $this->assignmentService->assign(
                    $worksheet,
                    $enrollment,
                    $assigner,
                    $dueDate,
                    'Catch-up practice',
                );

                $created[] = [
                    'set_code' => $setCode,
                    'worksheet_id' => $worksheet->id,
                    'assignment_id' => $assignment->id,
                    'student_enrollment_id' => $enrollmentId,
                    'student_name' => $studentName,
                    'question_count' => count($questionIds),
                ];
            }
        });

        if ($created === []) {
            throw new InvalidArgumentException('No catch-up sets were created. Check that JSON student_enrollment_id and source_question_id values match the selected weak sums.');
        }

        return [
            'created' => $created,
            'prompt_students' => $weakByEnrollment->count(),
        ];
    }

    /**
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function weakItemsByEnrollment(SyllabusTopic $topic): Collection
    {
        $alreadyCovered = Worksheet::query()
            ->where('purpose', WorksheetPurpose::CATCH_UP)
            ->where('syllabus_topic_id', $topic->id)
            ->whereNotNull('catch_up_for_enrollment_id')
            ->get(['catch_up_for_enrollment_id', 'catch_up_source_question_ids'])
            ->groupBy('catch_up_for_enrollment_id')
            ->map(function (Collection $rows) {
                return $rows->flatMap(fn (Worksheet $w) => $w->catch_up_source_question_ids ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values()
                    ->all();
            });

        $rows = GuidedAttemptQuestion::query()
            ->with([
                'question.options',
                'question.blankAnswer',
                'attempt.assignment.practiceSet',
                'attempt.assignment.enrollment.student:id,name',
            ])
            ->where('first_try_correct', false)
            ->where(function ($q) {
                $q->where('wrong_before_explanation', '>', 0)
                    ->orWhere('used_early_hint', true)
                    ->orWhere('corrected_after_help', true)
                    ->orWhere('gave_up', true);
            })
            ->whereHas('question', fn ($q) => $q->where('syllabus_topic_id', $topic->id))
            ->whereHas('attempt', function ($q) {
                $q->where('status', SetAttempt::STATUS_SUBMITTED)
                    ->where('mode', SetAttempt::MODE_GUIDED)
                    ->whereHas('assignment.practiceSet', function ($w) {
                        $w->where(function ($inner) {
                            $inner->whereNull('purpose')
                                ->orWhere('purpose', WorksheetPurpose::STANDARD);
                        });
                    });
            })
            ->orderByDesc('id')
            ->get();

        $byEnrollment = [];

        foreach ($rows as $row) {
            $enrollment = $row->attempt?->assignment?->enrollment;
            $practiceSet = $row->attempt?->assignment?->practiceSet;
            $question = $row->question;

            if (! $enrollment || ! $practiceSet || ! $question) {
                continue;
            }

            $enrollmentId = (int) $enrollment->id;
            $questionId = (int) $question->id;
            $covered = $alreadyCovered->get($enrollmentId, []);

            if (in_array($questionId, $covered, true)) {
                continue;
            }

            if (isset($byEnrollment[$enrollmentId][$questionId])) {
                continue;
            }

            $reason = match (true) {
                (bool) $row->gave_up => 'asked_help',
                (bool) $row->used_early_hint => 'used_hint',
                (bool) $row->corrected_after_help => 'corrected_after_help',
                default => 'wrong_first_try',
            };

            $byEnrollment[$enrollmentId][$questionId] = [
                'question_id' => $questionId,
                'type' => $question->type,
                'question_text' => $question->question_text,
                'options' => $question->isMcq()
                    ? $question->options->pluck('option_text')->values()->all()
                    : [],
                'answer_format' => $question->blankAnswer?->answer_format,
                'correct_answer' => $question->blankAnswer?->correct_answer,
                'set_code' => $practiceSet->set_code,
                'worksheet_id' => $practiceSet->id,
                'tier' => $practiceSet->tier,
                'reason' => $reason,
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name ?? 'Student',
            ];
        }

        return collect($byEnrollment)->map(fn (array $items) => collect(array_values($items)));
    }

    private function nextCatchUpCode(string $parentCode, int $enrollmentId, int $topicId): string
    {
        $parentCode = preg_replace('/-PC\d+$/i', '', trim($parentCode)) ?: 'SET';
        $prefix = $parentCode.'-PC';

        $existing = Worksheet::query()
            ->where('purpose', WorksheetPurpose::CATCH_UP)
            ->where('catch_up_for_enrollment_id', $enrollmentId)
            ->where('syllabus_topic_id', $topicId)
            ->where('set_code', 'like', $prefix.'%')
            ->pluck('set_code');

        $max = 0;
        foreach ($existing as $code) {
            if (preg_match('/-PC(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        return $prefix.($max + 1);
    }

    /**
     * @return list<array{student_enrollment_id: int, variants: list<array<string, mixed>>}>
     */
    private function parseBatchJson(string $json): array
    {
        $json = trim($json);
        if (preg_match('/^```(?:json)?\s*(.*?)```\s*$/is', $json, $matches)) {
            $json = trim($matches[1]);
        }

        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON. Paste the Cursor {"students": [...]} object.');
        }

        $students = $data['students'] ?? null;
        if (! is_array($students) || $students === []) {
            throw new InvalidArgumentException('JSON must include a non-empty "students" array.');
        }

        $parsed = [];

        foreach ($students as $block) {
            if (! is_array($block)) {
                continue;
            }

            $enrollmentId = (int) ($block['student_enrollment_id'] ?? $block['enrollment_id'] ?? 0);
            $variants = $block['variants'] ?? $block['questions'] ?? [];

            if ($enrollmentId < 1 || ! is_array($variants) || $variants === []) {
                continue;
            }

            $normalizedVariants = [];
            foreach ($variants as $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $sourceId = (int) ($variant['source_question_id'] ?? 0);
                if ($sourceId < 1) {
                    continue;
                }

                $type = strtolower(trim((string) ($variant['type'] ?? '')));
                if ($type === '' || $type === 'mcq') {
                    $type = ! empty($variant['options']) || isset($variant['correct_index'])
                        ? Question::TYPE_MCQ
                        : (isset($variant['correct_answer']) || isset($variant['answer_format'])
                            ? Question::TYPE_FILL_IN_BLANK
                            : Question::TYPE_MCQ);
                } elseif (in_array($type, ['fill_in_blank', 'fib', 'fill-blank', 'blank'], true)) {
                    $type = Question::TYPE_FILL_IN_BLANK;
                } else {
                    $type = Question::TYPE_MCQ;
                }

                $normalizedVariants[] = array_merge($variant, [
                    'source_question_id' => $sourceId,
                    'type' => $type,
                ]);
            }

            if ($normalizedVariants === []) {
                continue;
            }

            $parsed[] = [
                'student_enrollment_id' => $enrollmentId,
                'variants' => $normalizedVariants,
            ];
        }

        if ($parsed === []) {
            throw new InvalidArgumentException('Could not parse any student variants from JSON.');
        }

        return $parsed;
    }
}
