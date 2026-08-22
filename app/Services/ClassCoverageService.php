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
            return [
                'chapters' => [],
                'under_study_chapter_id' => null,
                'availability_columns' => $emptyColumns,
            ];
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

        $underStudyId = null;
        $chapters = $chapterOptions->values()->map(function (array $chapter) use ($coverages, $summaryById, $availabilityColumns, &$underStudyId) {
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
                'items' => $this->formatDetailItems($summaryChapter['items'] ?? []),
            ];
        })->sort(function (array $left, array $right) {
            $rank = static fn (array $chapter): int => match (true) {
                (bool) ($chapter['studied'] ?? false) => 0,
                (bool) ($chapter['under_study'] ?? false) => 1,
                default => 2,
            };

            $byStatus = $rank($left) <=> $rank($right);
            if ($byStatus !== 0) {
                return $byStatus;
            }

            $byNumber = ((int) ($left['chapter_number'] ?? 0)) <=> ((int) ($right['chapter_number'] ?? 0));
            if ($byNumber !== 0) {
                return $byNumber;
            }

            return ((int) $left['id']) <=> ((int) $right['id']);
        })->values()->all();

        return [
            'chapters' => $chapters,
            'under_study_chapter_id' => $underStudyId,
            'availability_columns' => $availabilityColumns,
        ];
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

        $main = array_values(array_filter($items, fn (array $item) => ! ($item['is_correction'] ?? false)));
        $corrections = array_values(array_filter($items, fn (array $item) => (bool) ($item['is_correction'] ?? false)));

        $total = count($main);
        $done = count(array_filter($main, fn (array $item) => ($item['status'] ?? '') === 'done'));
        $completionPct = $total > 0 ? (int) round(($done / $total) * 100) : null;

        $scored = array_values(array_filter(
            $main,
            fn (array $item) => isset($item['score_percent']) && $item['score_percent'] !== null && $item['score_percent'] !== '',
        ));
        $scorePct = $scored !== []
            ? (int) round(array_sum(array_map(fn (array $item) => (float) $item['score_percent'], $scored)) / count($scored))
            : null;

        $correctionDone = count(array_filter($corrections, fn (array $item) => ($item['status'] ?? '') === 'done'));
        $correctionPending = count(array_filter($corrections, fn (array $item) => ($item['status'] ?? '') !== 'done'));
        $openWrongs = (int) array_sum(array_map(
            fn (array $item) => (int) ($item['correction_count'] ?? 0),
            array_filter(
                $main,
                fn (array $item) => (int) ($item['correction_count'] ?? 0) > 0 && ($item['can_redo_wrong'] ?? false),
            ),
        ));

        return [
            'total' => $total,
            'done' => $done,
            'completion_pct' => $completionPct,
            'score_pct' => $scorePct,
            'scored_count' => count($scored),
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
                }
            }

            foreach (['formula', 'practice_correction', 'books'] as $key) {
                foreach ($itemsPayload[$key]['items'] ?? [] as $item) {
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
            'assignment_id' => $item['assignment_id'] ?? null,
            'target_date' => $item['target_date'] ?? null,
            'latest_attempt_id' => $item['latest_attempt_id'] ?? null,
            'delivery_mode' => $item['delivery_mode'] ?? null,
            'can_assign' => (bool) ($item['can_assign'] ?? false),
            'can_open' => (bool) ($item['can_open'] ?? false),
            'can_redo_wrong' => (bool) ($item['can_redo_wrong'] ?? false),
            'correction_count' => (int) ($item['correction_count'] ?? 0),
            'is_correction' => (bool) ($item['is_correction'] ?? false),
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
