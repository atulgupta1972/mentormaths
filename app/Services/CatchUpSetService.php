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
     * Students with pending weak sums (optionally filtered by class / chapter / topic).
     *
     * @return list<array<string, mixed>>
     */
    public function weakStudentsOverview(
        ?int $gradeLevelId = null,
        ?int $chapterId = null,
        ?int $topicId = null,
    ): array {
        $grouped = $this->weakItemsByEnrollment($gradeLevelId, $chapterId, $topicId);

        return $grouped->map(function (Collection $items, int $enrollmentId) {
            $first = $items->first();
            $topics = $items->groupBy('topic_id')->map(function (Collection $topicItems) {
                $row = $topicItems->first();

                return [
                    'topic_id' => $row['topic_id'],
                    'topic_name' => $row['topic_name'],
                    'chapter_name' => $row['chapter_name'],
                    'weak_count' => $topicItems->count(),
                ];
            })->values()->all();

            return [
                'student_enrollment_id' => $enrollmentId,
                'student_id' => $first['student_id'],
                'student_name' => $first['student_name'],
                'grade_name' => $first['grade_name'],
                'weak_count' => $items->count(),
                'topics' => $topics,
                'items' => $items->values()->all(),
            ];
        })->sortBy('student_name')->values()->all();
    }

    /**
     * @deprecated Use weakStudentsOverview()
     *
     * @return list<array<string, mixed>>
     */
    public function weakStudentsForTopic(SyllabusTopic $topic): array
    {
        return $this->weakStudentsOverview(null, null, $topic->id);
    }

    /**
     * @param  list<int>  $enrollmentIds
     */
    public function buildBatchPrompt(
        array $enrollmentIds,
        ?int $gradeLevelId = null,
        ?int $chapterId = null,
        ?int $topicId = null,
    ): string {
        $grouped = $this->weakItemsByEnrollment($gradeLevelId, $chapterId, $topicId)
            ->only(array_map('intval', $enrollmentIds))
            ->filter(fn (Collection $items) => $items->isNotEmpty());

        if ($grouped->isEmpty()) {
            throw new InvalidArgumentException('No pending weak sums for the selected students.');
        }

        $allItems = $grouped->flatten(1);
        $topicNames = $allItems->pluck('topic_name')->unique()->filter()->values();
        $chapterNames = $allItems->pluck('chapter_name')->unique()->filter()->values();
        $gradeNames = $allItems->pluck('grade_name')->unique()->filter()->values();

        $context = collect([
            $gradeNames->isNotEmpty() ? 'Class: '.$gradeNames->implode(', ') : null,
            $chapterNames->isNotEmpty() ? 'Chapter(s): '.$chapterNames->implode(', ') : null,
            $topicNames->isNotEmpty() ? 'Topic(s): '.$topicNames->implode(', ') : null,
            'Students selected: '.$grouped->count(),
            'Total weak sums: '.$allItems->count(),
        ])->filter()->implode("\n");

        $studentBlocks = [];

        foreach ($grouped as $enrollmentId => $items) {
            $name = $items->first()['student_name'];
            $lines = [];

            foreach ($items->values() as $index => $item) {
                $n = $index + 1;
                $type = $item['type'] === Question::TYPE_FILL_IN_BLANK ? 'fill_in_blank' : 'mcq';
                $lines[] = "Source #{$n} (source_question_id={$item['question_id']}, syllabus_topic_id={$item['topic_id']}, type={$type}, set={$item['set_code']}, topic=\"{$item['topic_name']}\", reason={$item['reason']}):";
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
Create catch-up practice variants for students who struggled. Return ONLY valid JSON (no markdown fences).

Context:
{$context}

Goal:
- For EACH source question below, create ONE similar question with different numbers / variables / wording.
- Keep the same skill and difficulty feel. Do not make it much harder or much easier.
- Keep the same type as the source (mcq stays mcq; fill_in_blank stays fill_in_blank).
- Keep the same syllabus_topic_id as the source on each variant.
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
          "syllabus_topic_id": 5,
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
          "syllabus_topic_id": 5,
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
     * @param  list<int>  $enrollmentIds
     * @return array{created: list<array<string, mixed>>, prompt_students: int}
     */
    public function importAndCreate(
        string $json,
        array $enrollmentIds,
        User $assigner,
        string $dueDate,
        ?int $gradeLevelId = null,
        ?int $chapterId = null,
        ?int $topicId = null,
    ): array {
        $allowed = collect($enrollmentIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $payload = $this->parseBatchJson($json);

        $weakByEnrollment = $this->weakItemsByEnrollment($gradeLevelId, $chapterId, $topicId)
            ->only($allowed->all());

        $sourceTopicMap = Question::query()
            ->whereIn('id', $weakByEnrollment->flatten(1)->pluck('question_id')->unique()->all())
            ->pluck('syllabus_topic_id', 'id');

        $created = [];

        DB::transaction(function () use ($payload, $weakByEnrollment, $assigner, $dueDate, $sourceTopicMap, &$created) {
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

                $byTopic = $variants->groupBy(function (array $variant) use ($sourceTopicMap, $weakItems) {
                    $sourceId = (int) $variant['source_question_id'];
                    if (! empty($variant['syllabus_topic_id'])) {
                        return (int) $variant['syllabus_topic_id'];
                    }

                    $fromWeak = $weakItems->firstWhere('question_id', $sourceId);

                    return (int) ($fromWeak['topic_id'] ?? $sourceTopicMap[$sourceId] ?? 0);
                });

                foreach ($byTopic as $variantTopicId => $topicVariants) {
                    $variantTopicId = (int) $variantTopicId;
                    if ($variantTopicId < 1) {
                        continue;
                    }

                    $topic = SyllabusTopic::query()->find($variantTopicId);
                    if (! $topic) {
                        continue;
                    }

                    $topicWeak = $weakItems->where('topic_id', $variantTopicId);
                    $createdSet = $this->createCatchUpWorksheetForStudent(
                        $topic,
                        $enrollment,
                        $topicWeak,
                        $topicVariants->values()->all(),
                        $assigner,
                        $dueDate,
                    );

                    if ($createdSet !== null) {
                        $created[] = $createdSet;
                    }
                }
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
     * @param  Collection<int, array<string, mixed>>  $weakItems
     * @param  list<array<string, mixed>>  $variants
     * @return array<string, mixed>|null
     */
    private function createCatchUpWorksheetForStudent(
        SyllabusTopic $topic,
        StudentEnrollment $enrollment,
        Collection $weakItems,
        array $variants,
        User $assigner,
        string $dueDate,
    ): ?array {
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
            return null;
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

        $enrollmentId = (int) $enrollment->id;
        $setCode = $this->nextCatchUpCode($parentCode);
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

        return [
            'set_code' => $setCode,
            'worksheet_id' => $worksheet->id,
            'assignment_id' => $assignment->id,
            'student_enrollment_id' => $enrollmentId,
            'student_name' => $studentName,
            'topic_name' => $topic->name,
            'question_count' => count($questionIds),
        ];
    }

    /**
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function weakItemsByEnrollment(
        ?int $gradeLevelId = null,
        ?int $chapterId = null,
        ?int $topicId = null,
    ): Collection {
        $alreadyCoveredQuery = Worksheet::query()
            ->where('purpose', WorksheetPurpose::CATCH_UP)
            ->whereNotNull('catch_up_for_enrollment_id');

        if ($topicId) {
            $alreadyCoveredQuery->where('syllabus_topic_id', $topicId);
        }

        $alreadyCovered = $alreadyCoveredQuery
            ->get(['catch_up_for_enrollment_id', 'catch_up_source_question_ids', 'syllabus_topic_id'])
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
                'question.topic.chapter.syllabusVersion.gradeLevel',
                'attempt.assignment.practiceSet',
                'attempt.assignment.enrollment.student:id,name',
                'attempt.assignment.enrollment.gradeLevel:id,name',
            ])
            ->where('first_try_correct', false)
            ->where(function ($q) {
                $q->where('wrong_before_explanation', '>', 0)
                    ->orWhere('used_early_hint', true)
                    ->orWhere('corrected_after_help', true)
                    ->orWhere('gave_up', true);
            })
            ->whereHas('question', function ($q) use ($topicId, $chapterId, $gradeLevelId) {
                if ($topicId) {
                    $q->where('syllabus_topic_id', $topicId);
                }
                if ($chapterId) {
                    $q->whereHas('topic', fn ($t) => $t->where('syllabus_chapter_id', $chapterId));
                }
                if ($gradeLevelId) {
                    $q->whereHas('topic.chapter.syllabusVersion', fn ($v) => $v->where('grade_level_id', $gradeLevelId));
                }
            })
            ->whereHas('attempt', function ($q) use ($gradeLevelId) {
                $q->where('mode', SetAttempt::MODE_GUIDED)
                    ->whereIn('status', [
                        SetAttempt::STATUS_SUBMITTED,
                        SetAttempt::STATUS_IN_PROGRESS,
                    ])
                    ->whereHas('assignment.practiceSet', function ($w) {
                        $w->where(function ($inner) {
                            $inner->whereNull('purpose')
                                ->orWhere('purpose', WorksheetPurpose::STANDARD);
                        });
                    });

                if ($gradeLevelId) {
                    $q->whereHas('assignment.enrollment', fn ($e) => $e->where('grade_level_id', $gradeLevelId));
                }
            })
            ->where(function ($q) {
                // Submitted sets: include all weak rows. In-progress sets: only finished questions.
                $q->whereHas('attempt', fn ($a) => $a->where('status', SetAttempt::STATUS_SUBMITTED))
                    ->orWhereIn('phase', [
                        GuidedAttemptQuestion::PHASE_DONE,
                        GuidedAttemptQuestion::PHASE_GIVEN_UP,
                    ]);
            })
            ->orderByDesc('id')
            ->get();

        $byEnrollment = [];

        foreach ($rows as $row) {
            $enrollment = $row->attempt?->assignment?->enrollment;
            $practiceSet = $row->attempt?->assignment?->practiceSet;
            $question = $row->question;
            $topic = $question?->topic;

            if (! $enrollment || ! $practiceSet || ! $question || ! $topic) {
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
                'topic_id' => $topic->id,
                'topic_name' => $topic->name,
                'chapter_name' => $topic->chapter?->name,
                'grade_name' => $enrollment->gradeLevel?->name
                    ?? $topic->chapter?->syllabusVersion?->gradeLevel?->name,
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

    /**
     * Globally unique catch-up codes (worksheets.set_code is unique).
     * Example: SF711-PC1, SF711-PC2 across all students.
     */
    private function nextCatchUpCode(string $parentCode): string
    {
        $parentCode = preg_replace('/-PC\d+$/i', '', trim($parentCode)) ?: 'SET';
        $prefix = $parentCode.'-PC';

        $existing = Worksheet::query()
            ->where('set_code', 'like', $prefix.'%')
            ->pluck('set_code');

        $max = 0;
        foreach ($existing as $code) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/i', (string) $code, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }

        $seq = $max + 1;

        // Safety: skip any collision (e.g. concurrent import / odd legacy codes).
        while (Worksheet::query()->where('set_code', $prefix.$seq)->exists()) {
            $seq++;
        }

        return $prefix.$seq;
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
                    'syllabus_topic_id' => isset($variant['syllabus_topic_id'])
                        ? (int) $variant['syllabus_topic_id']
                        : null,
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
