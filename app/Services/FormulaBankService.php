<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetDeliveryMode;
use App\Support\WorksheetPurpose;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FormulaBankService
{
    public function __construct(private McqImportService $mcqImport) {}

    /**
     * Class × chapter matrix of formula / concept card counts.
     *
     * @return array{
     *     board: array{id: int, code: string, name: string},
     *     grades: list<array{id: int, name: string, sort_order: int}>,
     *     rows: list<array{chapter_name: string, cells: array<int, array{chapter_id: int|null, formulas_count: int, sets_count: int}>}>
     * }
     */
    public function matrixForBoard(Board $board, ?AcademicYear $year = null): array
    {
        $year ??= AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $grades = GradeLevel::query()
            ->where('is_active', true)
            ->whereBetween('sort_order', [7, 10])
            ->orderBy('sort_order')
            ->get(['id', 'name', 'sort_order']);

        if (! $year || ! $maths) {
            return [
                'board' => $board->only(['id', 'code', 'name']),
                'grades' => $grades->map->only(['id', 'name', 'sort_order'])->values()->all(),
                'rows' => [],
            ];
        }

        /** @var array<string, array<int, array{chapter_id: int|null, formulas_count: int, sets_count: int}>> $byChapterName */
        $byChapterName = [];

        foreach ($grades as $grade) {
            $syllabus = SyllabusVersion::query()
                ->where('academic_year_id', $year->id)
                ->where('grade_level_id', $grade->id)
                ->where('board_id', $board->id)
                ->where('subject_id', $maths->id)
                ->first();

            if (! $syllabus) {
                continue;
            }

            $chapters = SyllabusChapter::query()
                ->where('syllabus_version_id', $syllabus->id)
                ->with(['topics:id,syllabus_chapter_id'])
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order']);

            foreach ($chapters as $chapter) {
                $topicIds = $chapter->topics->pluck('id');
                $formulasCount = $topicIds->isEmpty()
                    ? 0
                    : Question::query()
                        ->whereIn('syllabus_topic_id', $topicIds)
                        ->where('bank_purpose', QuestionBankPurpose::FORMULA)
                        ->count();

                $setsCount = Worksheet::query()
                    ->where('purpose', WorksheetPurpose::FORMULA)
                    ->where(function ($q) use ($chapter, $topicIds) {
                        $q->where(function ($inner) use ($chapter) {
                            $inner->where('scope', PracticeSetScope::CHAPTER)
                                ->where('syllabus_chapter_id', $chapter->id);
                        })->orWhere(function ($inner) use ($topicIds) {
                            $inner->where('scope', PracticeSetScope::TOPIC)
                                ->whereIn('syllabus_topic_id', $topicIds);
                        });
                    })
                    ->count();

                $name = trim((string) $chapter->name);
                $byChapterName[$name] ??= [];
                $byChapterName[$name][$grade->id] = [
                    'chapter_id' => $chapter->id,
                    'formulas_count' => $formulasCount,
                    'sets_count' => $setsCount,
                ];
            }
        }

        ksort($byChapterName, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = [];
        foreach ($byChapterName as $chapterName => $cellsByGrade) {
            $cells = [];
            foreach ($grades as $grade) {
                $cells[$grade->id] = $cellsByGrade[$grade->id] ?? [
                    'chapter_id' => null,
                    'formulas_count' => 0,
                    'sets_count' => 0,
                ];
            }

            $rows[] = [
                'chapter_name' => $chapterName,
                'cells' => $cells,
            ];
        }

        return [
            'board' => $board->only(['id', 'code', 'name']),
            'grades' => $grades->map->only(['id', 'name', 'sort_order'])->values()->all(),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function chaptersForClass(GradeLevel $grade, Board $board, ?AcademicYear $year = null): array
    {
        $year ??= AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $year || ! $maths) {
            return [];
        }

        $syllabus = SyllabusVersion::query()
            ->where('academic_year_id', $year->id)
            ->where('grade_level_id', $grade->id)
            ->where('board_id', $board->id)
            ->where('subject_id', $maths->id)
            ->first();

        if (! $syllabus) {
            return [];
        }

        return SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabus->id)
            ->with(['topics' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->map(function (SyllabusChapter $chapter) {
                $topicIds = $chapter->topics->pluck('id');
                $formulasCount = $topicIds->isEmpty()
                    ? 0
                    : Question::query()
                        ->whereIn('syllabus_topic_id', $topicIds)
                        ->where('bank_purpose', QuestionBankPurpose::FORMULA)
                        ->count();

                $setsCount = Worksheet::query()
                    ->where('purpose', WorksheetPurpose::FORMULA)
                    ->where(function ($q) use ($chapter, $topicIds) {
                        $q->where(function ($inner) use ($chapter) {
                            $inner->where('scope', PracticeSetScope::CHAPTER)
                                ->where('syllabus_chapter_id', $chapter->id);
                        })->orWhere(function ($inner) use ($topicIds) {
                            $inner->where('scope', PracticeSetScope::TOPIC)
                                ->whereIn('syllabus_topic_id', $topicIds);
                        });
                    })
                    ->count();

                return [
                    'id' => $chapter->id,
                    'name' => $chapter->name,
                    'sort_order' => $chapter->sort_order,
                    'topics_count' => $chapter->topics->count(),
                    'formulas_count' => $formulasCount,
                    'sets_count' => $setsCount,
                    'topics' => $chapter->topics->map(fn (SyllabusTopic $topic) => [
                        'id' => $topic->id,
                        'name' => $topic->name,
                        'formulas_count' => Question::query()
                            ->where('syllabus_topic_id', $topic->id)
                            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
                            ->count(),
                        'sets_count' => Worksheet::query()
                            ->where('purpose', WorksheetPurpose::FORMULA)
                            ->where('scope', PracticeSetScope::TOPIC)
                            ->where('syllabus_topic_id', $topic->id)
                            ->count(),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function topicDetail(SyllabusTopic $topic): array
    {
        $topic->loadMissing([
            'chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.board',
        ]);

        $sets = Worksheet::query()
            ->where('purpose', WorksheetPurpose::FORMULA)
            ->where('scope', PracticeSetScope::TOPIC)
            ->where('syllabus_topic_id', $topic->id)
            ->withCount('questions')
            ->orderBy('set_number')
            ->get()
            ->map(fn (Worksheet $set) => [
                'id' => $set->id,
                'title' => $set->title,
                'set_number' => $set->set_number,
                'set_code' => $set->set_code,
                'status' => $set->status,
                'questions_count' => $set->questions_count,
                'display_title' => $set->display_title,
            ])
            ->values()
            ->all();

        $unpacked = Question::query()
            ->where('syllabus_topic_id', $topic->id)
            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
            ->whereDoesntHave('worksheets', fn ($q) => $q->where('purpose', WorksheetPurpose::FORMULA))
            ->with('options')
            ->orderBy('id')
            ->get()
            ->map(fn (Question $q) => $this->questionPayload($q))
            ->values()
            ->all();

        return [
            'id' => $topic->id,
            'name' => $topic->name,
            'chapter' => [
                'id' => $topic->chapter?->id,
                'name' => $topic->chapter?->name,
            ],
            'grade' => $topic->chapter?->syllabusVersion?->gradeLevel?->only(['id', 'name']),
            'board' => $topic->chapter?->syllabusVersion?->board?->only(['id', 'code', 'name']),
            'sets' => $sets,
            'unpacked_formulas' => $unpacked,
            'formulas_count' => Question::query()
                ->where('syllabus_topic_id', $topic->id)
                ->where('bank_purpose', QuestionBankPurpose::FORMULA)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setDetail(Worksheet $worksheet): array
    {
        abort_unless($worksheet->isFormula(), 404);

        $worksheet->loadMissing([
            'topic.chapter.syllabusVersion.gradeLevel',
            'topic.chapter.syllabusVersion.board',
            'questions.options',
        ])->loadCount('questions');

        return [
            'id' => $worksheet->id,
            'title' => $worksheet->title,
            'set_number' => $worksheet->set_number,
            'set_code' => $worksheet->set_code,
            'status' => $worksheet->status,
            'display_title' => $worksheet->display_title,
            'questions_count' => $worksheet->questions_count,
            'topic' => [
                'id' => $worksheet->topic?->id,
                'name' => $worksheet->topic?->name,
                'chapter_id' => $worksheet->topic?->syllabus_chapter_id,
                'chapter_name' => $worksheet->topic?->chapter?->name,
            ],
            'grade' => $worksheet->topic?->chapter?->syllabusVersion?->gradeLevel?->only(['id', 'name']),
            'board' => $worksheet->topic?->chapter?->syllabusVersion?->board?->only(['id', 'code', 'name']),
            'questions' => $worksheet->questions->map(fn (Question $q) => $this->questionPayload($q))->values()->all(),
        ];
    }

    public function createSet(SyllabusTopic $topic, User $user, ?string $title = null): Worksheet
    {
        $topic->loadMissing('chapter.syllabusVersion.gradeLevel');

        $nextNumber = (int) Worksheet::query()
            ->where('purpose', WorksheetPurpose::FORMULA)
            ->where('scope', PracticeSetScope::TOPIC)
            ->where('syllabus_topic_id', $topic->id)
            ->max('set_number') + 1;

        $gradeSort = $topic->chapter?->syllabusVersion?->gradeLevel?->sort_order ?? 0;
        $chapterNumber = $topic->chapter?->chapter_number ?? $topic->chapter?->sort_order ?? 0;
        $setCode = sprintf('F%d%d%d', $gradeSort, $chapterNumber, $nextNumber);

        while (Worksheet::query()->where('set_code', $setCode)->exists()) {
            $nextNumber++;
            $setCode = sprintf('F%d%d%d', $gradeSort, $chapterNumber, $nextNumber);
        }

        return Worksheet::query()->create([
            'title' => $title !== null && trim($title) !== ''
                ? trim($title)
                : 'Formula set '.$nextNumber.' — '.$topic->name,
            'set_number' => $nextNumber,
            'set_code' => $setCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'syllabus_chapter_id' => null,
            'status' => Worksheet::STATUS_PUBLISHED,
            'purpose' => WorksheetPurpose::FORMULA,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Import formula/concept MCQs into a topic (optionally attach to a set).
     *
     * @return array{created: int, set: Worksheet|null}
     */
    public function importJson(SyllabusTopic $topic, string $json, User $user, ?Worksheet $set = null): array
    {
        if ($set && (! $set->isFormula() || $set->syllabus_topic_id !== $topic->id)) {
            throw new InvalidArgumentException('Formula set does not belong to this topic.');
        }

        $rows = $this->mcqImport->parseJson($json);

        return DB::transaction(function () use ($topic, $rows, $user, $set) {
            $questions = $this->mcqImport->saveRows(
                $topic,
                $rows,
                $user->id,
                Question::SOURCE_MANUAL,
                QuestionBankPurpose::FORMULA,
            );

            if ($set && $questions !== []) {
                $start = (int) $set->questions()->max('worksheet_question.sort_order');
                $attach = [];
                foreach ($questions as $index => $question) {
                    $attach[$question->id] = ['sort_order' => $start + $index + 1];
                }
                $set->questions()->attach($attach);
            }

            return [
                'created' => count($questions),
                'set' => $set?->fresh(),
            ];
        });
    }

    /**
     * Package unpacked formula cards into a new set (or append to existing).
     */
    public function packageUnpacked(SyllabusTopic $topic, User $user, ?Worksheet $set = null): Worksheet
    {
        $ids = Question::query()
            ->where('syllabus_topic_id', $topic->id)
            ->where('bank_purpose', QuestionBankPurpose::FORMULA)
            ->whereDoesntHave('worksheets', fn ($q) => $q->where('purpose', WorksheetPurpose::FORMULA))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        if ($ids === []) {
            throw new InvalidArgumentException('No unpacked formula cards to package.');
        }

        $set ??= $this->createSet($topic, $user);
        $start = (int) $set->questions()->max('worksheet_question.sort_order');
        $attach = [];
        foreach ($ids as $index => $id) {
            $attach[$id] = ['sort_order' => $start + $index + 1];
        }
        $set->questions()->attach($attach);

        return $set->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function questionPayload(Question $question): array
    {
        return [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'explanation' => $question->explanation,
            'options' => $question->options
                ->sortBy('sort_order')
                ->values()
                ->map(fn ($opt) => [
                    'id' => $opt->id,
                    'option_text' => $opt->option_text,
                    'is_correct' => (bool) $opt->is_correct,
                ])
                ->all(),
        ];
    }

    /**
     * Cursor prompt for formula / concept revision MCQs on one topic.
     *
     * @param  array{total?: int, focus?: string, style?: string}  $options
     */
    public function cursorPromptForTopic(SyllabusTopic $topic, array $options = []): string
    {
        $topic->loadMissing([
            'chapter.syllabusVersion.board',
            'chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.academicYear',
        ]);

        $total = max(1, min(40, (int) ($options['total'] ?? 8)));
        $focus = trim((string) ($options['focus'] ?? ''));
        $style = trim((string) ($options['style'] ?? 'mixed'));

        $styleGuide = match ($style) {
            'formula_recall' => 'Mostly formula-recall cards: show a situation or ask "which formula…" / complete the identity.',
            'concept' => 'Mostly concept cards: definitions, properties, when to use a rule, true/false style as MCQ.',
            'identify' => 'Mostly identify / match cards: given an expression or figure description, pick the correct formula or concept name.',
            default => 'Mix of formula recall, concept checks, and “which formula applies” MCQs.',
        };

        $focusBlock = $focus !== ''
            ? "Teacher notes — cover these formulas / concepts (priority):\n{$focus}"
            : 'Cover the key formulas and concepts for this topic at the class level.';

        $context = $this->topicContext($topic);

        return <<<PROMPT
Create FORMULA / CONCEPT revision MCQs for 5-minute daily drill. Return ONLY valid JSON (no markdown fences).

These are NOT calculation practice sums. Students pick the correct formula, identity, definition, or concept.

Context:
{$context}

Requirements:
- Exactly {$total} MCQ cards
- {$styleGuide}
- Class-appropriate CBSE/ICSE language
- 4 options each, exactly one correct answer
- Prefer short stems (one line when possible) so students can revise quickly
- Wrong options should be common mix-ups (sign errors, wrong identity, confusing area/perimeter, etc.)
- Include "explanation": one-line reminder of the correct formula/concept for the teacher
- Set "difficulty" to Easy, Medium, or Hard
- Do NOT invent off-syllabus formulas

{$focusBlock}

JSON format:
{
  "questions": [
    {
      "question": "Which identity equals (a + b)²?",
      "options": ["a² + b²", "a² + 2ab + b²", "a² − 2ab + b²", "(a + b)(a − b)"],
      "correct_index": 1,
      "explanation": "(a + b)² = a² + 2ab + b²",
      "difficulty": "Easy"
    }
  ]
}
PROMPT;
    }

    /**
     * Cursor prompt for formula / concept MCQs across a chapter (topics named in each item).
     *
     * @param  array{total?: int, focus?: string, style?: string, topic_ids?: list<int>}  $options
     */
    public function cursorPromptForChapter(SyllabusChapter $chapter, array $options = []): string
    {
        $chapter->loadMissing([
            'topics' => fn ($q) => $q->orderBy('sort_order'),
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $total = max(1, min(60, (int) ($options['total'] ?? 12)));
        $focus = trim((string) ($options['focus'] ?? ''));
        $style = trim((string) ($options['style'] ?? 'mixed'));
        $topicIds = collect($options['topic_ids'] ?? [])->filter()->map(fn ($id) => (int) $id)->all();

        $topics = $chapter->topics;
        if ($topicIds !== []) {
            $topics = $topics->whereIn('id', $topicIds)->values();
        }

        if ($topics->isEmpty()) {
            throw new InvalidArgumentException('Select at least one topic for the formula prompt.');
        }

        $topicLines = $topics->map(fn (SyllabusTopic $t) => '- '.$t->name)->implode("\n");
        $styleGuide = match ($style) {
            'formula_recall' => 'Mostly formula-recall cards.',
            'concept' => 'Mostly concept / definition cards.',
            'identify' => 'Mostly identify-which-formula cards.',
            default => 'Mix of formula recall, concepts, and identify-which-formula MCQs.',
        };

        $focusBlock = $focus !== ''
            ? "Teacher notes — cover these formulas / concepts (priority):\n{$focus}"
            : 'Spread cards across the listed topics; emphasise core formulas students must memorise.';

        $version = $chapter->syllabusVersion;
        $context = collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            "Chapter: {$chapter->chapter_number} — {$chapter->name}",
            "Topics to cover:\n{$topicLines}",
        ])->filter()->implode("\n");

        return <<<PROMPT
Create FORMULA / CONCEPT revision MCQs for 5-minute daily drill. Return ONLY valid JSON (no markdown fences).

These are NOT calculation practice sums. Students pick the correct formula, identity, definition, or concept.

Context:
{$context}

Requirements:
- Exactly {$total} MCQ cards total across the topics listed
- Each question MUST include "topic" set to the exact topic name from the list above
- {$styleGuide}
- Class-appropriate CBSE/ICSE language
- 4 options each, exactly one correct answer
- Short stems for quick revision
- Wrong options = common mix-ups
- Include "explanation": one-line formula/concept reminder
- Set "difficulty" to Easy, Medium, or Hard

{$focusBlock}

JSON format:
{
  "questions": [
    {
      "topic": "Exact topic name from list",
      "question": "Area of a rectangle with length l and breadth b is:",
      "options": ["2(l + b)", "l × b", "l + b", "πr²"],
      "correct_index": 1,
      "explanation": "Area of rectangle = l × b",
      "difficulty": "Easy"
    }
  ]
}
PROMPT;
    }

    private function topicContext(SyllabusTopic $topic): string
    {
        $chapter = $topic->chapter;
        $version = $chapter?->syllabusVersion;

        return collect([
            $version ? "Board: {$version->board->code}" : null,
            $version ? "Class: {$version->gradeLevel->name}" : null,
            $version ? "Academic year: {$version->academicYear->name}" : null,
            $chapter ? "Chapter: {$chapter->chapter_number} — {$chapter->name}" : null,
            "Topic: {$topic->name}",
            $topic->learning_outcomes ? "Key concepts: {$topic->learning_outcomes}" : null,
        ])->filter()->implode("\n");
    }
}
