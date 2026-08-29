<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\SyllabusChapter;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\SyllabusChapterMatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ClassCoverageService
{
    public function __construct(
        private ExamPlanService $examPlanService,
        private StudentChapterSummaryService $chapterSummaryService,
        private FormulaBankService $formulaBank,
        private SetAssignmentService $assignmentService,
    ) {}

    /**
     * @return array{
     *     chapters: list<array<string, mixed>>,
     *     under_study_chapter_id: int|null,
     *     availability_columns: list<array{key: string, label: string, short: string}>
     * }
     */
    public function forEnrollment(?StudentEnrollment $enrollment): array
    {
        $emptyColumns = $this->defaultAvailabilityColumns();

        if (! $enrollment) {
            return self::emptyPayload($emptyColumns);
        }

        $chapterOptions = $this->examPlanService->chapterOptionsForEnrollment($enrollment);

        // Topic-level formula sets show as many "Fm1" cards — merge into one chapter set first.
        $this->formulaBank->ensureChaptersHaveSingleFormulaSet(
            $chapterOptions->pluck('id')->all(),
            auth()->user(),
        );

        $coverages = StudentChapterCoverage::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->whereIn('syllabus_chapter_id', $chapterOptions->pluck('id'))
            ->get()
            ->keyBy('syllabus_chapter_id');

        $summary = $this->chapterSummaryService->forEnrollment($enrollment);
        $summaryById = collect($summary['chapters'] ?? [])->keyBy('id');
        $availabilityColumns = $this->availabilityColumnsFor($summary['book_columns'] ?? []);
        $otherGroups = $summary['other_groups'] ?? [];
        $underStudyId = null;

        $chapters = $chapterOptions->values()->map(function (array $chapter) use (
            $coverages,
            $summaryById,
            $availabilityColumns,
            &$underStudyId,
        ) {
            $coverage = $coverages->get($chapter['id']);
            $isStudied = $coverage?->status === StudentChapterCoverage::STATUS_STUDIED;
            $isUnderStudy = $coverage?->status === StudentChapterCoverage::STATUS_UNDER_STUDY;

            if ($isUnderStudy) {
                $underStudyId = (int) $chapter['id'];
            }

            $summaryChapter = $summaryById->get($chapter['id']);
            $counts = $summaryChapter['counts'] ?? [];
            $availability = [];
            foreach ($availabilityColumns as $column) {
                $key = $column['key'];
                if (str_starts_with($key, 'book:')) {
                    $bookId = substr($key, 5);
                    $availability[$key] = (int) ($counts['books'][$bookId] ?? 0);
                } else {
                    $availability[$key] = (int) ($counts[$key] ?? 0);
                }
            }

            $rawItems = $summaryChapter['items'] ?? [];

            return [
                'id' => $chapter['id'],
                'chapter_number' => $chapter['chapter_number'],
                'name' => $chapter['name'],
                'label' => $chapter['label'],
                'topics' => collect($chapter['topics'] ?? [])->pluck('name')->values()->all(),
                'topics_label' => collect($chapter['topics'] ?? [])->pluck('name')->implode(', '),
                'studied' => $isStudied,
                'under_study' => $isUnderStudy,
                'availability' => $availability,
                'items' => $this->formatDetailItems($rawItems),
            ];
        })->sort(function (array $left, array $right) {
            $byNumber = strcmp(
                SyllabusChapter::orderKey((string) ($left['chapter_number'] ?? '')),
                SyllabusChapter::orderKey((string) ($right['chapter_number'] ?? '')),
            );
            if ($byNumber !== 0) {
                return $byNumber;
            }

            return ((int) $left['id']) <=> ((int) $right['id']);
        })->values()->all();

        $chapterChoices = collect($chapters)->map(fn (array $chapter) => [
            'id' => (int) $chapter['id'],
            'label' => (string) ($chapter['label'] ?? $chapter['name'] ?? 'Chapter'),
        ])->values()->all();

        $additionalGroups = app(SetCoverageGrouping::class)->formatAdditionalGroups(
            $otherGroups,
            fn (array $item) => $this->detailItemPayload(array_merge($item, [
                'can_move_chapter' => true,
                'is_additional' => true,
            ])),
        );

        return [
            'chapters' => $chapters,
            'under_study_chapter_id' => $underStudyId,
            'availability_columns' => $availabilityColumns,
            'additional_groups' => $additionalGroups,
            'chapter_choices' => $chapterChoices,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, short: string}>|null  $availabilityColumns
     * @return array{
     *     chapters: list<array<string, mixed>>,
     *     under_study_chapter_id: null,
     *     availability_columns: list<array{key: string, label: string, short: string}>,
     *     additional_groups: list<array<string, mixed>>,
     *     chapter_choices: list<array<string, mixed>>
     * }
     */
    public static function emptyPayload(?array $availabilityColumns = null): array
    {
        return [
            'chapters' => [],
            'under_study_chapter_id' => null,
            'availability_columns' => $availabilityColumns ?? [
                ['key' => 'practice', 'label' => 'Practice set', 'short' => 'Prac'],
                ['key' => 'practice_correction', 'label' => 'Practice correction', 'short' => 'Corr'],
                ['key' => 'test', 'label' => 'Test', 'short' => 'Test'],
                ['key' => 'written', 'label' => 'Written', 'short' => 'Writ'],
                ['key' => 'fill_blank', 'label' => 'Fill in blank', 'short' => 'Fill'],
            ],
            'additional_groups' => [],
            'chapter_choices' => [],
        ];
    }

    /**
     * True when the student has marked at least one chapter Studied or Under study.
     * Daily drills unlock only after this (first time and thereafter).
     */
    public function hasMarkedStudyPlan(?StudentEnrollment $enrollment): bool
    {
        if (! $enrollment) {
            return false;
        }

        return StudentChapterCoverage::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->exists();
    }

    /**
     * Overall study-plan status for chapters marked Studied / Under study (matches dashboard card).
     *
     * @return array{
     *     total: int,
     *     done: int,
     *     completion_pct: int|null,
     *     score_pct: int|null,
     *     scored_count: int,
     *     correction_done: int,
     *     correction_pending: int,
     *     open_wrongs: int,
     *     chapter_count: int,
     *     chapter_labels: list<string>
     * }|null
     */
    public function studyPlanPerformance(?StudentEnrollment $enrollment): ?array
    {
        if (! $enrollment) {
            return null;
        }

        return $this->studyPlanPerformanceFromCoverage($this->forEnrollment($enrollment));
    }

    /**
     * @param  array{chapters?: list<array<string, mixed>>}  $coverage
     * @return array{
     *     total: int,
     *     done: int,
     *     completion_pct: int|null,
     *     score_pct: int|null,
     *     scored_count: int,
     *     correction_done: int,
     *     correction_pending: int,
     *     open_wrongs: int,
     *     chapter_count: int,
     *     chapter_labels: list<string>
     * }|null
     */
    public function studyPlanPerformanceFromCoverage(array $coverage): ?array
    {
        $tracked = collect($coverage['chapters'] ?? [])
            ->filter(fn (array $chapter) => ($chapter['studied'] ?? false) || ($chapter['under_study'] ?? false))
            ->values();

        if ($tracked->isEmpty()) {
            return null;
        }

        $items = [];
        $labels = [];

        foreach ($tracked as $chapter) {
            $items = array_merge($items, $this->collectChapterItems($chapter));

            $number = trim((string) ($chapter['chapter_number'] ?? ''));
            if ($number !== '') {
                $labels[] = str_starts_with(strtolower($number), 'ch')
                    ? $number
                    : 'Ch '.$number;
            } else {
                $labels[] = (string) ($chapter['name'] ?? 'Chapter');
            }
        }

        foreach ($coverage['additional_groups'] ?? [] as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
        }

        $main = array_values(array_filter(
            $items,
            fn (array $item) => ! ($item['is_correction'] ?? false) && ! ($item['is_revision'] ?? false),
        ));
        $revisionItems = array_values(array_filter($items, fn (array $item) => (bool) ($item['is_revision'] ?? false)));
        $corrections = array_values(array_filter($items, fn (array $item) => (bool) ($item['is_correction'] ?? false)));

        $mainAgg = \App\Support\SumPoolAggregate::fromItems($main);
        $revisionAgg = \App\Support\SumPoolAggregate::fromItems($revisionItems);

        $correctionDone = count(array_filter($corrections, fn (array $item) => ($item['status'] ?? '') === 'done'));
        $correctionPending = count(array_filter($corrections, fn (array $item) => ($item['status'] ?? '') !== 'done'));
        $openWrongs = (int) array_sum(array_map(
            fn (array $item) => (int) ($item['correction_count'] ?? 0),
            array_filter(
                $items,
                fn (array $item) => (int) ($item['correction_count'] ?? 0) > 0 && ($item['can_redo_wrong'] ?? false),
            ),
        ));

        return [
            'total' => $mainAgg['pool'],
            'done' => $mainAgg['attempted'],
            'correct' => $mainAgg['correct'],
            'completion_pct' => $mainAgg['completion_pct'],
            'score_pct' => $mainAgg['score_pct'],
            'scored_count' => $mainAgg['correct'],
            'set_total' => $mainAgg['set_total'],
            'set_done' => $mainAgg['set_done'],
            'revision_total' => $revisionAgg['pool'],
            'revision_done' => $revisionAgg['attempted'],
            'revision_correct' => $revisionAgg['correct'],
            'revision_completion_pct' => $revisionAgg['completion_pct'],
            'revision_score_pct' => $revisionAgg['score_pct'],
            'revision_scored_count' => $revisionAgg['correct'],
            'correction_done' => $correctionDone,
            'correction_pending' => $correctionPending,
            'open_wrongs' => $openWrongs,
            'chapter_count' => $tracked->count(),
            'chapter_labels' => array_slice($labels, 0, 6),
        ];
    }

    /**
     * @param  array<string, mixed>  $chapter
     * @return list<array<string, mixed>>
     */
    private function collectChapterItems(array $chapter): array
    {
        $itemsPayload = $chapter['items'] ?? [];

        if (($itemsPayload['layout'] ?? null) === 'tier_blocks') {
            $collected = [];

            foreach ($itemsPayload['blocks'] ?? [] as $block) {
                foreach ($block['rows'] ?? [] as $row) {
                    foreach ($row['items'] ?? [] as $item) {
                        $collected[] = $item;
                    }
                    foreach ($row['revision_items'] ?? [] as $item) {
                        $collected[] = $item;
                    }
                }
            }

            foreach (['formula', 'practice_correction', 'books'] as $key) {
                foreach ($itemsPayload[$key]['items'] ?? [] as $item) {
                    $collected[] = $item;
                }
            }

            foreach ($itemsPayload['book_groups'] ?? [] as $book) {
                foreach ($book['revision_items'] ?? [] as $item) {
                    $collected[] = $item;
                }
            }

            $otherGroups = $itemsPayload['other_groups'] ?? [];
            if ($otherGroups !== []) {
                foreach ($otherGroups as $group) {
                    foreach ($group['items'] ?? [] as $item) {
                        $collected[] = $item;
                    }
                }
            } else {
                foreach ($itemsPayload['other']['items'] ?? [] as $item) {
                    $collected[] = $item;
                }
            }

            return $collected;
        }

        if (! is_array($itemsPayload)) {
            return [];
        }

        // Legacy flat groups: [{ items: [...] }, ...]
        $collected = [];
        foreach ($itemsPayload as $group) {
            if (! is_array($group)) {
                continue;
            }
            foreach ($group['items'] ?? [] as $item) {
                if (is_array($item)) {
                    $collected[] = $item;
                }
            }
        }

        return $collected;
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function formatDetailItems(array $items): array
    {
        return app(SetCoverageGrouping::class)->formatDashboard($items, fn (array $item) => $this->detailItemPayload($item));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function detailItemPayload(array $item): array
    {
        $percent = $item['latest_score_percent'] ?? null;
        $statusLabel = (string) ($item['status_label'] ?? 'NOT DONE');

        // Prefer explicit score in brackets when DONE already embeds it; otherwise append.
        $displayStatus = $statusLabel;
        if ($percent !== null && ! str_contains($statusLabel, '(')) {
            $displayStatus = $statusLabel.' ('.$percent.'%)';
        }

        return [
            'worksheet_id' => $item['worksheet_id'] ?? null,
            'short_label' => $item['short_label'] ?? ($item['set_code'] ?? 'Set'),
            'set_code' => $item['set_code'] ?? null,
            'question_count' => (int) ($item['question_count'] ?? 0),
            'status' => $item['status'] ?? 'not_assigned',
            'status_label' => $displayStatus,
            'score_percent' => $percent,
            'completion_pct' => $item['completion_pct'] ?? ($item['pool_metrics']['completion_pct'] ?? null),
            'pool_metrics' => $item['pool_metrics'] ?? null,
            'assignment_id' => $item['assignment_id'] ?? null,
            'target_date' => $item['target_date'] ?? null,
            'latest_attempt_id' => $item['latest_attempt_id'] ?? null,
            'in_progress_attempt_id' => $item['in_progress_attempt_id'] ?? null,
            'delivery_mode' => $item['delivery_mode'] ?? null,
            'can_assign' => (bool) ($item['can_assign'] ?? false),
            'can_open' => (bool) ($item['can_open'] ?? false),
            'can_redo_wrong' => (bool) ($item['can_redo_wrong'] ?? false),
            'correction_count' => (int) ($item['correction_count'] ?? 0),
            'is_correction' => (bool) ($item['is_correction'] ?? false),
            'is_revision' => (bool) ($item['is_revision'] ?? false),
            'revision_number' => (int) ($item['revision_number'] ?? 0),
            'can_start_revision' => (bool) ($item['can_start_revision'] ?? false),
            'is_cross_chapter' => (bool) ($item['is_cross_chapter'] ?? false),
            'is_additional' => (bool) ($item['is_additional'] ?? false),
            'can_move_chapter' => (bool) ($item['can_move_chapter'] ?? false),
            'source_label' => $item['source_label'] ?? null,
            'textbook_id' => $item['textbook_id'] ?? null,
            'textbook_name' => $item['textbook_name'] ?? null,
        ];
    }

    /**
     * @param  list<array{id?: int|string, label?: string, code?: string, name?: string}>  $bookColumns
     * @return list<array{key: string, label: string, short: string}>
     */
    private function availabilityColumnsFor(array $bookColumns): array
    {
        $columns = $this->defaultAvailabilityColumns();

        foreach ($bookColumns as $book) {
            $id = (string) ($book['id'] ?? '');
            if ($id === '') {
                continue;
            }

            $label = (string) ($book['label'] ?? $book['code'] ?? $book['name'] ?? 'Book');
            $short = strtoupper(substr(preg_replace('/\s+/', '', (string) ($book['code'] ?? 'B')), 0, 3)) ?: 'BK';

            $columns[] = [
                'key' => 'book:'.$id,
                'label' => $label,
                'short' => $short,
            ];
        }

        return $columns;
    }

    /**
     * @return list<array{key: string, label: string, short: string}>
     */
    private function defaultAvailabilityColumns(): array
    {
        return [
            ['key' => 'practice', 'label' => 'Practice set', 'short' => 'Prac'],
            ['key' => 'practice_correction', 'label' => 'Practice correction', 'short' => 'Corr'],
            ['key' => 'test', 'label' => 'Test', 'short' => 'Test'],
            ['key' => 'written', 'label' => 'Written', 'short' => 'Writ'],
            ['key' => 'fill_blank', 'label' => 'Fill in blank', 'short' => 'Fill'],
        ];
    }

    public function markUnderStudy(StudentEnrollment $enrollment, SyllabusChapter $chapter): void
    {
        $this->assertChapterBelongsToEnrollment($enrollment, $chapter);

        $now = now();

        DB::transaction(function () use ($enrollment, $chapter, $now) {
            StudentChapterCoverage::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->where('status', StudentChapterCoverage::STATUS_UNDER_STUDY)
                ->where('syllabus_chapter_id', '!=', $chapter->id)
                ->delete();

            StudentChapterCoverage::query()->updateOrCreate(
                [
                    'student_enrollment_id' => $enrollment->id,
                    'syllabus_chapter_id' => $chapter->id,
                ],
                [
                    'status' => StudentChapterCoverage::STATUS_UNDER_STUDY,
                    'studied_at' => null,
                    'marked_under_study_at' => $now,
                ],
            );
        });

        $this->assignChapterContentDueToday($enrollment, $chapter);
    }

    public function clearCoverage(StudentEnrollment $enrollment, SyllabusChapter $chapter): void
    {
        $this->assertChapterBelongsToEnrollment($enrollment, $chapter);

        StudentChapterCoverage::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('syllabus_chapter_id', $chapter->id)
            ->delete();
    }

    public function markStudied(StudentEnrollment $enrollment, SyllabusChapter $chapter): void
    {
        $this->assertChapterBelongsToEnrollment($enrollment, $chapter);

        $now = now();

        DB::transaction(function () use ($enrollment, $chapter, $now) {
            StudentChapterCoverage::query()->updateOrCreate(
                [
                    'student_enrollment_id' => $enrollment->id,
                    'syllabus_chapter_id' => $chapter->id,
                ],
                [
                    'status' => StudentChapterCoverage::STATUS_STUDIED,
                    'studied_at' => $now,
                    'marked_under_study_at' => null,
                ],
            );
        });

        $this->assignChapterContentDueToday($enrollment, $chapter);
    }

    /**
     * Assign (or refresh due date to today) all publishable chapter sets for this student.
     * Skips completed work and formula-only cards (daily drill covers those).
     */
    public function assignChapterContentDueToday(
        StudentEnrollment $enrollment,
        SyllabusChapter $chapter,
        ?User $assigner = null,
    ): int {
        $assigner = $assigner
            ?? auth()->user()
            ?? $enrollment->student?->user;

        if (! $assigner) {
            return 0;
        }

        $dueDate = now()->toDateString();
        $assigned = 0;

        foreach ($this->publishableWorksheetsForChapter($chapter) as $worksheet) {
            $existing = SetAssignment::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->where('worksheet_id', $worksheet->id)
                ->whereNot('status', SetAssignment::STATUS_CANCELLED)
                ->orderByDesc('id')
                ->first();

            if ($existing?->status === SetAssignment::STATUS_COMPLETED) {
                continue;
            }

            try {
                $this->assignmentService->assign(
                    $worksheet,
                    $enrollment,
                    $assigner,
                    $dueDate,
                    'Auto-assigned when chapter marked Studied / Under study',
                );
                $assigned++;
            } catch (\InvalidArgumentException $e) {
                Log::info('Study-plan auto-assign skipped a set.', [
                    'worksheet_id' => $worksheet->id,
                    'enrollment_id' => $enrollment->id,
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('Study-plan auto-assign failed for a set.', [
                    'worksheet_id' => $worksheet->id,
                    'enrollment_id' => $enrollment->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $assigned;
    }

    /**
     * When a new published set appears, assign it (due today) to every student who already
     * marked that chapter Studied or Under study. Admin/mentor can still amend due dates later.
     */
    public function assignNewWorksheetDueToday(Worksheet $worksheet, ?User $assigner = null): int
    {
        if ($worksheet->status !== Worksheet::STATUS_PUBLISHED) {
            return 0;
        }

        if ($worksheet->isFormula() || $worksheet->isCatchUp()) {
            return 0;
        }

        $chapterId = $this->syllabusChapterIdForWorksheet($worksheet);
        if (! $chapterId) {
            return 0;
        }

        $assigner = $assigner
            ?? auth()->user()
            ?? $worksheet->creator;

        if (! $assigner) {
            return 0;
        }

        $dueDate = now()->toDateString();
        $assigned = 0;

        $coverages = StudentChapterCoverage::query()
            ->with(['enrollment.student.user'])
            ->where('syllabus_chapter_id', $chapterId)
            ->whereIn('status', [
                StudentChapterCoverage::STATUS_STUDIED,
                StudentChapterCoverage::STATUS_UNDER_STUDY,
            ])
            ->get();

        foreach ($coverages as $coverage) {
            $enrollment = $coverage->enrollment;
            if (! $enrollment || $enrollment->status !== StudentEnrollment::STATUS_ACTIVE) {
                continue;
            }

            $existing = SetAssignment::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->where('worksheet_id', $worksheet->id)
                ->whereNot('status', SetAssignment::STATUS_CANCELLED)
                ->orderByDesc('id')
                ->first();

            if ($existing?->status === SetAssignment::STATUS_COMPLETED) {
                continue;
            }

            try {
                $this->assignmentService->assign(
                    $worksheet,
                    $enrollment,
                    $assigner,
                    $dueDate,
                    'Auto-assigned — new set for studied chapter',
                );
                $assigned++;
            } catch (\InvalidArgumentException $e) {
                Log::info('New-set study-plan auto-assign skipped.', [
                    'worksheet_id' => $worksheet->id,
                    'enrollment_id' => $enrollment->id,
                    'message' => $e->getMessage(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('New-set study-plan auto-assign failed.', [
                    'worksheet_id' => $worksheet->id,
                    'enrollment_id' => $enrollment->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $assigned;
    }

    /**
     * @param  iterable<int|Worksheet>  $worksheets
     */
    public function assignNewWorksheetsDueToday(iterable $worksheets, ?User $assigner = null): int
    {
        $total = 0;

        foreach ($worksheets as $worksheet) {
            $model = $worksheet instanceof Worksheet
                ? $worksheet
                : Worksheet::query()->find((int) $worksheet);

            if (! $model) {
                continue;
            }

            $total += $this->assignNewWorksheetDueToday($model, $assigner);
        }

        return $total;
    }

    public function syllabusChapterIdForContent(Worksheet $worksheet): ?int
    {
        return $this->syllabusChapterIdForWorksheet($worksheet);
    }

    /**
     * Chapter used for study-plan gating and coverage: admin remap, then name/head match, else worksheet chapter.
     */
    public function resolveEffectiveSyllabusChapterId(
        Worksheet $worksheet,
        StudentEnrollment $enrollment,
        ?SetAssignment $assignment = null,
    ): ?int {
        if ($assignment?->effective_syllabus_chapter_id) {
            return (int) $assignment->effective_syllabus_chapter_id;
        }

        $sourceId = $this->syllabusChapterIdForWorksheet($worksheet);
        $homeChapterIds = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($sourceId && in_array($sourceId, $homeChapterIds, true)) {
            return $sourceId;
        }

        $sourceChapter = $this->sourceChapterForWorksheet($worksheet);
        if ($sourceChapter && $homeChapterIds !== []) {
            $homeChapters = SyllabusChapter::query()
                ->whereIn('id', $homeChapterIds)
                ->get(['id', 'name', 'chapter_head_id']);

            $matched = SyllabusChapterMatch::matchHomeChapterId($sourceChapter, $homeChapters);
            if ($matched) {
                return $matched;
            }
        }

        return $sourceId;
    }

    /**
     * Whether the student may start this assignment given study-plan marks.
     */
    public function enrollmentCanAttemptContent(
        StudentEnrollment $enrollment,
        Worksheet $worksheet,
        ?SetAssignment $assignment = null,
    ): bool {
        $effectiveId = $this->resolveEffectiveSyllabusChapterId($worksheet, $enrollment, $assignment);

        if (! $effectiveId) {
            return true;
        }

        if ($this->enrollmentHasChapterInStudy($enrollment, $effectiveId)) {
            return true;
        }

        $homeChapterIds = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // Sheet belongs to (or was remapped/matched onto) home syllabus — that chapter must be marked.
        if (in_array($effectiveId, $homeChapterIds, true)) {
            return false;
        }

        // Cross-board / cross-class sheet with no home chapter mapping: allow once study plan is started.
        return $this->hasMarkedStudyPlan($enrollment);
    }

    public function enrollmentHasChapterInStudy(StudentEnrollment $enrollment, int $syllabusChapterId): bool
    {
        return StudentChapterCoverage::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('syllabus_chapter_id', $syllabusChapterId)
            ->whereIn('status', [
                StudentChapterCoverage::STATUS_STUDIED,
                StudentChapterCoverage::STATUS_UNDER_STUDY,
            ])
            ->exists();
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    public function homeChapterOptionsForEnrollment(StudentEnrollment $enrollment): array
    {
        return $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->map(fn (array $chapter) => [
                'id' => (int) $chapter['id'],
                'label' => (string) ($chapter['label'] ?? $chapter['name'] ?? ('Chapter '.$chapter['id'])),
            ])
            ->values()
            ->all();
    }

    public function assertEffectiveChapterBelongsToEnrollment(
        StudentEnrollment $enrollment,
        int $syllabusChapterId,
    ): void {
        $allowed = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->all();

        if (! in_array($syllabusChapterId, $allowed, true)) {
            throw ValidationException::withMessages([
                'effective_syllabus_chapter_id' => 'Pick a chapter from this student’s class syllabus.',
            ]);
        }
    }

    private function sourceChapterForWorksheet(Worksheet $worksheet): ?SyllabusChapter
    {
        $worksheet->loadMissing([
            'chapter:id,name,chapter_number,chapter_head_id,syllabus_version_id',
            'topic:id,name,syllabus_chapter_id',
            'topic.chapter:id,name,chapter_number,chapter_head_id,syllabus_version_id',
        ]);

        if ($worksheet->isChapterScope()) {
            return $worksheet->chapter;
        }

        return $worksheet->topic?->chapter;
    }

    private function syllabusChapterIdForWorksheet(Worksheet $worksheet): ?int
    {
        if ($worksheet->syllabus_chapter_id) {
            return (int) $worksheet->syllabus_chapter_id;
        }

        if ($worksheet->syllabus_topic_id) {
            $worksheet->loadMissing('topic:id,syllabus_chapter_id');

            return $worksheet->topic?->syllabus_chapter_id
                ? (int) $worksheet->topic->syllabus_chapter_id
                : null;
        }

        $fromTextbook = TextbookChapter::query()
            ->where(function ($q) use ($worksheet) {
                $q->where('mcq_worksheet_id', $worksheet->id)
                    ->orWhere('fill_blank_worksheet_id', $worksheet->id)
                    ->orWhere('written_worksheet_id', $worksheet->id);
            })
            ->value('syllabus_chapter_id');

        if ($fromTextbook) {
            return (int) $fromTextbook;
        }

        $linked = TextbookChapter::query()
            ->where(function ($q) {
                $q->whereNotNull('mcq_worksheet_ids')
                    ->orWhereNotNull('fill_blank_worksheet_ids')
                    ->orWhereNotNull('written_worksheet_ids');
            })
            ->get(['id', 'syllabus_chapter_id', 'mcq_worksheet_ids', 'fill_blank_worksheet_ids', 'written_worksheet_ids'])
            ->first(function (TextbookChapter $chapter) use ($worksheet) {
                return in_array($worksheet->id, $chapter->mcqWorksheetIds(), true)
                    || in_array($worksheet->id, $chapter->fillBlankWorksheetIds(), true)
                    || in_array($worksheet->id, $chapter->writtenWorksheetIds(), true);
            });

        return $linked?->syllabus_chapter_id ? (int) $linked->syllabus_chapter_id : null;
    }

    /**
     * Backfill: for every Studied / Under study mark, set chapter content due today.
     *
     * @return array{enrollments: int, chapters: int, assignments: int}
     */
    public function syncDueTodayForAllMarkedChapters(?int $enrollmentId = null): array
    {
        $query = StudentChapterCoverage::query()
            ->with(['enrollment.student.user', 'chapter.topics:id,syllabus_chapter_id'])
            ->whereIn('status', [
                StudentChapterCoverage::STATUS_STUDIED,
                StudentChapterCoverage::STATUS_UNDER_STUDY,
            ]);

        if ($enrollmentId !== null) {
            $query->where('student_enrollment_id', $enrollmentId);
        }

        $stats = ['enrollments' => 0, 'chapters' => 0, 'assignments' => 0];
        $seenEnrollments = [];

        foreach ($query->cursor() as $coverage) {
            $enrollment = $coverage->enrollment;
            $chapter = $coverage->chapter;

            if (! $enrollment || ! $chapter) {
                continue;
            }

            if (! isset($seenEnrollments[$enrollment->id])) {
                $seenEnrollments[$enrollment->id] = true;
                $stats['enrollments']++;
            }

            $stats['chapters']++;
            $stats['assignments'] += $this->assignChapterContentDueToday($enrollment, $chapter);
        }

        return $stats;
    }

    /**
     * @return Collection<int, Worksheet>
     */
    private function publishableWorksheetsForChapter(SyllabusChapter $chapter): Collection
    {
        $chapter->loadMissing('topics:id,syllabus_chapter_id');
        $topicIds = $chapter->topics->pluck('id')->all();

        $worksheets = Worksheet::query()
            ->where('status', Worksheet::STATUS_PUBLISHED)
            ->where(function ($query) use ($topicIds, $chapter) {
                $query->where(function ($inner) use ($topicIds) {
                    $inner->where('scope', PracticeSetScope::TOPIC)
                        ->whereIn('syllabus_topic_id', $topicIds ?: [-1]);
                })->orWhere(function ($inner) use ($chapter) {
                    $inner->where('scope', PracticeSetScope::CHAPTER)
                        ->where('syllabus_chapter_id', $chapter->id);
                });
            })
            ->get();

        $textbookWorksheetIds = TextbookChapter::query()
            ->where('syllabus_chapter_id', $chapter->id)
            ->get()
            ->flatMap(fn (TextbookChapter $row) => array_merge(
                $row->mcqWorksheetIds(),
                $row->fill_blank_worksheet_id ? [(int) $row->fill_blank_worksheet_id] : [],
                $row->written_worksheet_id ? [(int) $row->written_worksheet_id] : [],
            ))
            ->unique()
            ->values();

        if ($textbookWorksheetIds->isNotEmpty()) {
            $worksheets = $worksheets->merge(
                Worksheet::query()
                    ->where('status', Worksheet::STATUS_PUBLISHED)
                    ->whereIn('id', $textbookWorksheetIds)
                    ->get(),
            )->unique('id');
        }

        return $worksheets
            ->filter(fn (Worksheet $worksheet) => ! $worksheet->isFormula() && ! $worksheet->isCatchUp())
            ->values();
    }

    private function assertChapterBelongsToEnrollment(StudentEnrollment $enrollment, SyllabusChapter $chapter): void
    {
        $allowed = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->all();

        if (! in_array($chapter->id, $allowed, true)) {
            throw ValidationException::withMessages([
                'syllabus_chapter_id' => 'This chapter is not part of your class syllabus.',
            ]);
        }
    }
}
