<?php

namespace App\Services;

use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use App\Support\AssignmentProgress;
use App\Support\PracticeSetScope;
use App\Support\SyllabusChapterMatch;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Support\Collection;

class StudentChapterSummaryService
{
    public function __construct(
        private ExamPlanService $examPlanService,
    ) {}

    /**
     * @return array{
     *     book_columns: list<array<string, mixed>>,
     *     chapters: list<array<string, mixed>>,
     *     other_groups: list<array<string, mixed>>,
     *     context: array<string, mixed>
     * }
     */
    public function forEnrollment(
        StudentEnrollment $enrollment,
        ?int $gradeLevelId = null,
        ?int $boardId = null,
    ): array {
        $gradeLevelId = $gradeLevelId ?? $enrollment->grade_level_id;
        $boardId = $boardId ?? $enrollment->board_id;

        $syllabusVersion = $this->resolveSyllabusVersion($enrollment, $gradeLevelId, $boardId);

        $context = [
            'selected_grade_level_id' => $gradeLevelId,
            'selected_board_id' => $boardId,
            'home_grade_level_id' => $enrollment->grade_level_id,
            'home_board_id' => $enrollment->board_id,
            'is_home_class' => $gradeLevelId === $enrollment->grade_level_id
                && $boardId === $enrollment->board_id,
            'selected_grade_name' => $syllabusVersion?->gradeLevel?->name,
            'selected_board_name' => $syllabusVersion?->board?->name,
        ];

        if (! $syllabusVersion) {
            return [
                'book_columns' => [],
                'chapters' => [],
                'other_groups' => [],
                'context' => $context,
            ];
        }

        $syllabusVersion->loadMissing(['gradeLevel:id,name', 'board:id,name,code']);

        $chapters = SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabusVersion->id)
            ->orderBy('sort_order')
            ->get(['id', 'chapter_number', 'name', 'sort_order', 'chapter_head_id']);

        if ($chapters->isEmpty()) {
            return [
                'book_columns' => $this->textbookColumnsForGrade($gradeLevelId),
                'chapters' => [],
                'other_groups' => [],
                'context' => array_merge($context, [
                    'selected_grade_name' => $syllabusVersion->gradeLevel?->name,
                    'selected_board_name' => $syllabusVersion->board?->name,
                ]),
            ];
        }

        $chapterIds = $chapters->pluck('id')->all();
        $topicIds = SyllabusChapter::query()
            ->whereIn('id', $chapterIds)
            ->with(['topics:id,syllabus_chapter_id'])
            ->get()
            ->flatMap(fn (SyllabusChapter $chapter) => $chapter->topics->pluck('id'))
            ->unique()
            ->values()
            ->all();

        $worksheets = Worksheet::query()
            ->where('status', Worksheet::STATUS_PUBLISHED)
            ->where(function ($query) use ($topicIds, $chapterIds) {
                $query->where(function ($inner) use ($topicIds) {
                    $inner->where('scope', PracticeSetScope::TOPIC)
                        ->whereIn('syllabus_topic_id', $topicIds);
                })->orWhere(function ($inner) use ($chapterIds) {
                    $inner->where('scope', PracticeSetScope::CHAPTER)
                        ->whereIn('syllabus_chapter_id', $chapterIds);
                });
            })
            ->with([
                'topic:id,name,syllabus_chapter_id',
                'chapter:id,name,chapter_number',
                'questions:id,type',
            ])
            ->withCount('questions')
            ->orderBy('set_number')
            ->get();

        $textbookColumns = $this->textbookColumnsForGrade($gradeLevelId);
        $textbookChapters = $this->textbookChaptersForSyllabus($chapterIds, $textbookColumns);
        $textbookWorksheetIds = $textbookChapters
            ->flatMap(fn (TextbookChapter $row) => array_merge(
                $row->mcqWorksheetIds(),
                $row->fill_blank_worksheet_id ? [(int) $row->fill_blank_worksheet_id] : [],
                $row->written_worksheet_id ? [(int) $row->written_worksheet_id] : [],
            ))
            ->unique()
            ->values();

        $missingTextbookWorksheets = $textbookWorksheetIds
            ->diff($worksheets->pluck('id'))
            ->values();

        if ($missingTextbookWorksheets->isNotEmpty()) {
            $worksheets = $worksheets->merge(
                Worksheet::query()
                    ->whereIn('id', $missingTextbookWorksheets)
                    ->with([
                        'topic:id,name,syllabus_chapter_id',
                        'chapter:id,name,chapter_number',
                        'questions:id,type',
                    ])
                    ->withCount('questions')
                    ->get(),
            );
        }

        $assignmentsByWorksheet = SetAssignment::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->with([
                'practiceSet' => fn ($query) => $query->withCount('questions'),
                'attempts' => fn ($query) => $query->orderByDesc('attempt_number')->limit(1),
                'writtenSubmissions' => fn ($query) => $query->latest('id')->limit(1),
            ])
            ->orderByDesc('id')
            ->get()
            ->groupBy('worksheet_id');

        // Pending wrongs for badge/counts (also refined per-assignment via pool in buildSetItem).
        $correctionCountsByWorksheet = PracticeCorrectionItem::query()
            ->where('student_id', $enrollment->student_id)
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->selectRaw('worksheet_id, count(*) as aggregate')
            ->groupBy('worksheet_id')
            ->pluck('aggregate', 'worksheet_id');

        $worksheetsById = $worksheets->keyBy('id');
        $worksheetsByChapter = $this->groupWorksheetsByChapter($worksheets, $chapterIds);

        $chapterRows = $chapters->map(function (SyllabusChapter $chapter) use (
            $worksheetsByChapter,
            $worksheetsById,
            $textbookChapters,
            $textbookColumns,
            $assignmentsByWorksheet,
            $correctionCountsByWorksheet,
        ) {
            $chapterWorksheets = $worksheetsByChapter->get($chapter->id, collect());
            $chapterTextbookRows = $textbookChapters->where('syllabus_chapter_id', $chapter->id);

            $practice = [];
            $tests = [];
            $written = [];
            $fillBlank = [];
            $formula = [];
            $books = [];
            $revisions = [];

            foreach ($chapterWorksheets as $worksheet) {
                if ($this->isTextbookWorksheet($worksheet, $chapterTextbookRows)) {
                    continue;
                }

                if ($worksheet->isCatchUp()) {
                    continue;
                }

                $item = $this->buildSetItem(
                    $worksheet,
                    $assignmentsByWorksheet,
                    null,
                    (int) ($correctionCountsByWorksheet[$worksheet->id] ?? 0),
                );
                $bucket = $this->bucketForWorksheet($worksheet);

                match ($bucket) {
                    'test' => $tests[] = $item,
                    'written' => $written[] = $item,
                    'fill_blank' => $fillBlank[] = $item,
                    'formula' => $formula[] = $item,
                    default => $practice[] = $item,
                };

                foreach ($this->buildRevisionItems(
                    $worksheet,
                    $assignmentsByWorksheet,
                    $item['short_label'],
                ) as $revisionItem) {
                    $revisions[] = $revisionItem;
                }
            }

            // Correction stays on the parent / Rev card — no separate strip.
            $practiceCorrection = [];

            foreach ($textbookColumns as $bookColumn) {
                $bookItems = [];
                $seenWorksheetIds = [];

                foreach ($chapterTextbookRows->where('textbook_id', (int) $bookColumn['id']) as $textbookChapter) {
                    foreach ($textbookChapter->contentParts() as $part) {
                        foreach ([
                            ['mcq', 'MCQ', $part['mcq_worksheet_id']],
                            ['fill_blank', 'Fill-in-blank', $part['fill_blank_worksheet_id']],
                            ['written', 'Written', $part['written_worksheet_id']],
                        ] as [$kind, $kindLabel, $worksheetId]) {
                            if (! $worksheetId || isset($seenWorksheetIds[$worksheetId])) {
                                continue;
                            }

                            $seenWorksheetIds[$worksheetId] = true;
                            $worksheet = $worksheetsById->get($worksheetId);

                            if (! $worksheet) {
                                continue;
                            }

                            $item = $this->buildSetItem(
                                $worksheet,
                                $assignmentsByWorksheet,
                                'B',
                                (int) ($correctionCountsByWorksheet[$worksheet->id] ?? 0),
                            );
                            $item['part'] = $part['part'];
                            $item['kind'] = $kind;
                            $item['kind_label'] = $kindLabel;
                            $item['textbook_id'] = (int) $bookColumn['id'];
                            $item['textbook_name'] = (string) ($bookColumn['name'] ?? $bookColumn['label'] ?? 'Book');
                            $bookItems[] = $item;

                            foreach ($this->buildRevisionItems(
                                $worksheet,
                                $assignmentsByWorksheet,
                                $item['short_label'],
                                (int) $bookColumn['id'],
                                (string) ($bookColumn['name'] ?? $bookColumn['label'] ?? 'Book'),
                            ) as $revisionItem) {
                                $revisionItem['part'] = $part['part'];
                                $revisionItem['kind'] = $kind;
                                $revisionItem['kind_label'] = $kindLabel;
                                $revisions[] = $revisionItem;
                            }
                        }
                    }
                }

                $books[(string) $bookColumn['id']] = $bookItems;
            }

            return [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
                'label' => ExamPlanService::chapterLabel($chapter),
                'counts' => [
                    'practice' => count($practice),
                    'practice_correction' => 0,
                    'test' => count($tests),
                    'written' => count($written),
                    'fill_blank' => count($fillBlank),
                    'formula' => count($formula),
                    'books' => collect($books)->map(fn (array $items) => count($items))->all(),
                    'revisions' => count($revisions),
                ],
                'items' => [
                    'practice' => $practice,
                    'practice_correction' => $practiceCorrection,
                    'test' => $tests,
                    'written' => $written,
                    'fill_blank' => $fillBlank,
                    'formula' => $formula,
                    'books' => $books,
                    'revisions' => $revisions,
                ],
            ];
        })->values()->all();

        [$chapterRows, $otherGroups] = $this->mergeCrossChapterAssignments(
            $chapterRows,
            $chapters,
            $assignmentsByWorksheet,
            $correctionCountsByWorksheet,
            (int) $boardId,
        );

        return [
            'book_columns' => $textbookColumns,
            'chapters' => $chapterRows,
            'other_groups' => $otherGroups,
            'context' => array_merge($context, [
                'selected_grade_name' => $syllabusVersion->gradeLevel?->name,
                'selected_board_name' => $syllabusVersion->board?->name,
            ]),
        ];
    }

    /**
     * @return array{
     *     grade_levels: list<array<string, mixed>>,
     *     boards_by_grade: array<int, list<array<string, mixed>>>,
     *     selected_grade_level_id: int,
     *     selected_board_id: int,
     *     home_grade_level_id: int,
     *     home_board_id: int
     * }
     */
    public function filterOptions(
        StudentEnrollment $enrollment,
        ?int $selectedGradeLevelId = null,
        ?int $selectedBoardId = null,
    ): array {
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $maths) {
            return [
                'grade_levels' => [],
                'boards_by_grade' => [],
                'selected_grade_level_id' => $enrollment->grade_level_id,
                'selected_board_id' => $enrollment->board_id,
                'home_grade_level_id' => $enrollment->grade_level_id,
                'home_board_id' => $enrollment->board_id,
            ];
        }

        $versions = SyllabusVersion::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('subject_id', $maths->id)
            ->with(['gradeLevel:id,name,sort_order', 'board:id,code,name'])
            ->get();

        $gradeLevels = $versions
            ->pluck('gradeLevel')
            ->filter()
            ->unique('id')
            ->sortBy('sort_order')
            ->values()
            ->map(fn ($grade) => $grade->only(['id', 'name', 'sort_order']))
            ->all();

        $boardsByGrade = $versions
            ->groupBy('grade_level_id')
            ->map(fn (Collection $group) => $group
                ->pluck('board')
                ->filter()
                ->unique('id')
                ->sortBy('name')
                ->values()
                ->map(fn ($board) => $board->only(['id', 'code', 'name']))
                ->all())
            ->all();

        $selectedGradeLevelId = $selectedGradeLevelId ?? $enrollment->grade_level_id;
        $boardsForGrade = $boardsByGrade[$selectedGradeLevelId] ?? [];
        $selectedBoardId = $selectedBoardId ?? $enrollment->board_id;

        $boardIdsForGrade = collect($boardsForGrade)->pluck('id')->all();

        if ($boardIdsForGrade !== [] && ! in_array($selectedBoardId, $boardIdsForGrade, true)) {
            $selectedBoardId = $boardIdsForGrade[0];
        }

        return [
            'grade_levels' => $gradeLevels,
            'boards_by_grade' => $boardsByGrade,
            'selected_grade_level_id' => $selectedGradeLevelId,
            'selected_board_id' => $selectedBoardId,
            'home_grade_level_id' => $enrollment->grade_level_id,
            'home_board_id' => $enrollment->board_id,
        ];
    }

    private function resolveSyllabusVersion(
        StudentEnrollment $enrollment,
        int $gradeLevelId,
        int $boardId,
    ): ?SyllabusVersion {
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $maths) {
            return null;
        }

        return SyllabusVersion::query()
            ->where('academic_year_id', $enrollment->academic_year_id)
            ->where('grade_level_id', $gradeLevelId)
            ->where('board_id', $boardId)
            ->where('subject_id', $maths->id)
            ->first();
    }

    /**
     * @return list<array{id: int, code: string, name: string, label: string}>
     */
    private function textbookColumnsForGrade(int $gradeLevelId): array
    {
        return Textbook::query()
            ->where('grade_level_id', $gradeLevelId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn (Textbook $book) => [
                'id' => $book->id,
                'code' => $book->code,
                'name' => $book->name,
                'label' => $book->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $syllabusChapterIds
     * @param  list<array<string, mixed>>  $textbookColumns
     * @return Collection<int, TextbookChapter>
     */
    private function textbookChaptersForSyllabus(array $syllabusChapterIds, array $textbookColumns): Collection
    {
        if ($syllabusChapterIds === [] || $textbookColumns === []) {
            return collect();
        }

        $textbookIds = collect($textbookColumns)->pluck('id')->all();

        return TextbookChapter::query()
            ->whereIn('syllabus_chapter_id', $syllabusChapterIds)
            ->whereIn('textbook_id', $textbookIds)
            ->where('status', TextbookChapter::STATUS_PUBLISHED)
            ->get();
    }

    /**
     * Fold assigned worksheets from other class/board into home chapters when the chapter
     * name or chapter head matches; otherwise return them as Other groups.
     *
     * @param  list<array<string, mixed>>  $chapterRows
     * @param  Collection<int, SyllabusChapter>  $homeChapters
     * @param  Collection<int, Collection<int, SetAssignment>>  $assignmentsByWorksheet
     * @param  Collection<int, int|string>  $correctionCountsByWorksheet
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function mergeCrossChapterAssignments(
        array $chapterRows,
        Collection $homeChapters,
        Collection $assignmentsByWorksheet,
        Collection $correctionCountsByWorksheet,
        int $homeBoardId,
    ): array {
        $coveredIds = [];

        foreach ($chapterRows as $row) {
            foreach (['practice', 'practice_correction', 'test', 'written', 'fill_blank', 'formula'] as $key) {
                foreach ($row['items'][$key] ?? [] as $item) {
                    if (! empty($item['worksheet_id'])) {
                        $coveredIds[(int) $item['worksheet_id']] = true;
                    }
                }
            }

            foreach ($row['items']['books'] ?? [] as $bookItems) {
                if (! is_array($bookItems)) {
                    continue;
                }

                foreach ($bookItems as $item) {
                    if (is_array($item) && ! empty($item['worksheet_id'])) {
                        $coveredIds[(int) $item['worksheet_id']] = true;
                    }
                }
            }
        }

        $orphanWorksheetIds = $assignmentsByWorksheet->keys()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && ! isset($coveredIds[$id]))
            ->values();

        if ($orphanWorksheetIds->isEmpty()) {
            return [$chapterRows, []];
        }

        $orphanWorksheets = Worksheet::query()
            ->whereIn('id', $orphanWorksheetIds)
            ->where('status', Worksheet::STATUS_PUBLISHED)
            ->with([
                'topic:id,name,syllabus_chapter_id',
                'topic.chapter:id,name,chapter_number,chapter_head_id,syllabus_version_id',
                'topic.chapter.syllabusVersion.gradeLevel:id,name',
                'topic.chapter.syllabusVersion.board:id,name,code',
                'chapter:id,name,chapter_number,chapter_head_id,syllabus_version_id',
                'chapter.syllabusVersion.gradeLevel:id,name',
                'chapter.syllabusVersion.board:id,name,code',
                'questions:id,type',
            ])
            ->withCount('questions')
            ->get();

        if ($orphanWorksheets->isEmpty()) {
            return [$chapterRows, []];
        }

        $rowIndexById = [];
        foreach ($chapterRows as $index => $row) {
            $rowIndexById[(int) $row['id']] = $index;
        }

        $byName = [];
        $byHead = [];
        foreach ($homeChapters as $chapter) {
            $normalized = SyllabusChapterMatch::normalizeName((string) $chapter->name);
            if ($normalized !== '' && ! isset($byName[$normalized])) {
                $byName[$normalized] = (int) $chapter->id;
            }
            if ($chapter->chapter_head_id && ! isset($byHead[(int) $chapter->chapter_head_id])) {
                $byHead[(int) $chapter->chapter_head_id] = (int) $chapter->id;
            }
        }

        $otherBySource = [];

        foreach ($orphanWorksheets as $worksheet) {
            if ($worksheet->isCatchUp()) {
                continue;
            }

            $sourceChapter = $worksheet->isChapterScope()
                ? $worksheet->chapter
                : $worksheet->topic?->chapter;

            $currentAssignment = $this->resolveCurrentAssignment(
                $assignmentsByWorksheet->get($worksheet->id, collect()),
            );

            $item = $this->buildSetItem(
                $worksheet,
                $assignmentsByWorksheet,
                null,
                (int) ($correctionCountsByWorksheet[$worksheet->id] ?? 0),
            );
            $item['is_cross_chapter'] = true;

            $matchId = null;
            $overrideId = $currentAssignment?->effective_syllabus_chapter_id
                ? (int) $currentAssignment->effective_syllabus_chapter_id
                : null;

            if ($overrideId && isset($rowIndexById[$overrideId])) {
                $matchId = $overrideId;
            } elseif ($sourceChapter) {
                $matchId = SyllabusChapterMatch::matchHomeChapterId($sourceChapter, $homeChapters);
            }

            if ($matchId !== null && isset($rowIndexById[$matchId])) {
                $index = $rowIndexById[$matchId];
                $bucket = $this->bucketForWorksheet($worksheet);
                $chapterRows[$index]['items'][$bucket][] = $item;
                $chapterRows[$index]['counts'][$bucket] = (int) ($chapterRows[$index]['counts'][$bucket] ?? 0) + 1;

                continue;
            }

            $sourceKey = $sourceChapter?->id
                ? 'sc:'.$sourceChapter->id
                : 'ws:'.$worksheet->id;

            if (! isset($otherBySource[$sourceKey])) {
                $gradeName = $sourceChapter?->syllabusVersion?->gradeLevel?->name ?? 'Other class';
                $chapterName = $sourceChapter?->name
                    ?? (string) ($worksheet->title ?: $worksheet->set_code ?: 'Extra sheet');
                $boardName = $sourceChapter?->syllabusVersion?->board?->name;
                $boardCode = $sourceChapter?->syllabusVersion?->board?->code;
                $sourceBoardId = (int) ($sourceChapter?->syllabusVersion?->board_id ?? 0);
                $label = ($sourceBoardId > 0 && $sourceBoardId !== $homeBoardId && $boardCode)
                    ? "{$gradeName} · {$boardCode} - {$chapterName}"
                    : "{$gradeName} - {$chapterName}";

                $otherBySource[$sourceKey] = [
                    'id' => $sourceKey,
                    'label' => $label,
                    'grade_name' => $gradeName,
                    'board_name' => $boardName,
                    'chapter_name' => $chapterName,
                    'syllabus_chapter_id' => $sourceChapter?->id,
                    'items' => [],
                ];
            }

            $otherBySource[$sourceKey]['items'][] = $item;
        }

        return [$chapterRows, array_values($otherBySource)];
    }

    /**
     * @param  Collection<int, Worksheet>  $worksheets
     * @param  list<int>  $chapterIds
     * @return Collection<int, Collection<int, Worksheet>>
     */
    private function groupWorksheetsByChapter(Collection $worksheets, array $chapterIds): Collection
    {
        $grouped = collect($chapterIds)->mapWithKeys(fn (int $id) => [$id => collect()]);

        foreach ($worksheets as $worksheet) {
            $chapterId = $worksheet->isChapterScope()
                ? (int) $worksheet->syllabus_chapter_id
                : (int) ($worksheet->topic?->syllabus_chapter_id ?? 0);

            if ($chapterId && $grouped->has($chapterId)) {
                $grouped[$chapterId]->push($worksheet);
            }
        }

        return $grouped;
    }

    /**
     * @param  Collection<int, Collection<int, SetAssignment>>  $assignmentsByWorksheet
     * @return array<string, mixed>
     */
    private function buildSetItem(
        Worksheet $worksheet,
        Collection $assignmentsByWorksheet,
        ?string $prefixOverride = null,
        int $correctionCount = 0,
    ): array {
        $assignment = $this->resolveOriginalAssignment(
            $assignmentsByWorksheet->get($worksheet->id, collect()),
        );

        $progress = null;

        if ($assignment) {
            if ($worksheet->isWritten()) {
                $progress = AssignmentProgress::formatWrittenStudentDashboardSummary(
                    $assignment,
                    $assignment->writtenSubmissions->first(),
                );
            } else {
                $progress = AssignmentProgress::formatStudentDashboardSummary(
                    $assignment,
                    $assignment->attempts->first(),
                );
            }
        }

        $bucket = $this->bucketForWorksheet($worksheet);
        $prefix = $prefixOverride ?? match ($bucket) {
            'test' => 'T',
            'written' => 'W',
            'fill_blank' => 'F',
            'formula' => 'Fm',
            default => 'P',
        };

        $setNumber = $worksheet->set_number ?: 1;
        $shortLabel = "{$prefix}{$setNumber}";
        $statusMeta = $this->statusMeta($progress, $assignment !== null);

        $poolPending = (int) ($progress['pool_metrics']['pending_remedial'] ?? 0);
        if ($poolPending <= 0) {
            $poolPending = (int) ($progress['pool_metrics']['pending'] ?? 0);
            // Only treat pending as "wrongs to correct" when some work was already attempted.
            if (($progress['pool_metrics']['attempted'] ?? 0) <= 0) {
                $poolPending = 0;
            }
        }
        $effectiveCorrectionCount = $poolPending > 0 ? $poolPending : $correctionCount;

        // Learning sheet: Assign me only when not yet assigned. No full "Redo" — that is Revision.
        $canAssign = ($statusMeta['status'] ?? null) === 'not_assigned';

        return [
            'worksheet_id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'set_number' => $setNumber,
            'short_label' => $shortLabel,
            'tier' => $worksheet->tier,
            'tier_label' => $worksheet->tier_label,
            'question_count' => (int) ($worksheet->questions_count ?? 0),
            'delivery_mode' => $worksheet->delivery_mode ?? WorksheetDeliveryMode::ONLINE,
            'assignment_id' => $assignment?->id,
            'target_date' => $assignment?->due_date?->toDateString(),
            'latest_attempt_id' => $progress['latest_attempt_id'] ?? null,
            'in_progress_attempt_id' => $progress['in_progress_attempt_id'] ?? null,
            'status' => $statusMeta['status'],
            'status_label' => $statusMeta['status_label'],
            'can_assign' => $canAssign,
            'can_open' => $statusMeta['can_open'],
            'latest_score_percent' => $progress['latest_score_percent'] ?? null,
            'previous_score_percent' => $progress['previous_score_percent'] ?? null,
            'status_detail' => $progress['status_detail'] ?? null,
            'completion_pct' => $progress['completion_pct'] ?? null,
            'pool_metrics' => $progress['pool_metrics'] ?? null,
            'correction_count' => $effectiveCorrectionCount,
            'can_redo_wrong' => $effectiveCorrectionCount > 0,
            'is_correction' => false,
            'is_revision' => false,
            'revision_number' => 0,
            'can_start_revision' => false,
        ];
    }

    /**
     * @param  Collection<int, Collection<int, SetAssignment>>  $assignmentsByWorksheet
     * @return list<array<string, mixed>>
     */
    private function buildRevisionItems(
        Worksheet $worksheet,
        Collection $assignmentsByWorksheet,
        string $parentShortLabel,
        ?int $textbookId = null,
        ?string $textbookName = null,
    ): array {
        $assignments = $assignmentsByWorksheet->get($worksheet->id, collect())
            ->filter(fn (SetAssignment $a) => $a->isRevision())
            ->sortBy([
                ['revision_number', 'asc'],
                ['id', 'asc'],
            ])
            ->values();

        if ($assignments->isEmpty()) {
            return [];
        }

        // Show the latest revision card only (history lives in revision_number / scores).
        $assignment = $assignments->last();
        $progress = null;

        if ($worksheet->isWritten()) {
            $progress = AssignmentProgress::formatWrittenStudentDashboardSummary(
                $assignment,
                $assignment->writtenSubmissions->first(),
            );
        } else {
            $progress = AssignmentProgress::formatStudentDashboardSummary(
                $assignment,
                $assignment->attempts->first(),
            );
        }

        $statusMeta = $this->statusMeta($progress, true);
        $poolPending = (int) ($progress['pool_metrics']['pending_remedial'] ?? 0);
        $revNo = (int) $assignment->revision_number;
        $statusLabel = 'R'.$revNo.' · '.($statusMeta['status_label'] ?? 'NOT DONE');

        $item = [
            'worksheet_id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'set_number' => $worksheet->set_number ?: 1,
            'short_label' => 'Rev '.$parentShortLabel,
            'tier' => $worksheet->tier,
            'tier_label' => $worksheet->tier_label,
            'question_count' => (int) ($worksheet->questions_count ?? 0),
            'delivery_mode' => $worksheet->delivery_mode ?? WorksheetDeliveryMode::ONLINE,
            'assignment_id' => $assignment->id,
            'target_date' => $assignment->due_date?->toDateString(),
            'latest_attempt_id' => $progress['latest_attempt_id'] ?? null,
            'in_progress_attempt_id' => $progress['in_progress_attempt_id'] ?? null,
            'status' => $statusMeta['status'],
            'status_label' => $statusLabel,
            'can_assign' => ($statusMeta['status'] ?? null) === 'done',
            'can_open' => $statusMeta['can_open'],
            'latest_score_percent' => $progress['latest_score_percent'] ?? null,
            'previous_score_percent' => $progress['previous_score_percent'] ?? null,
            'status_detail' => $progress['status_detail'] ?? null,
            'completion_pct' => $progress['completion_pct'] ?? null,
            'pool_metrics' => $progress['pool_metrics'] ?? null,
            'correction_count' => $poolPending,
            'can_redo_wrong' => $poolPending > 0,
            'is_correction' => false,
            'is_revision' => true,
            'revision_number' => $revNo,
            'can_start_revision' => ($statusMeta['status'] ?? null) === 'done',
            'textbook_id' => $textbookId,
            'textbook_name' => $textbookName,
        ];

        return [$item];
    }

    /**
     * @param  Collection<int, SetAssignment>  $assignments
     */
    private function resolveOriginalAssignment(Collection $assignments): ?SetAssignment
    {
        $originals = $assignments->filter(fn (SetAssignment $a) => $a->isOriginalLearning());

        return $this->resolveCurrentAssignment($originals);
    }

    private function buildCorrectionItem(Worksheet $worksheet, int $wrongCount): array
    {
        $bucket = $this->bucketForWorksheet($worksheet);
        $prefix = match ($bucket) {
            'test' => 'T',
            'written' => 'W',
            'fill_blank' => 'F',
            'formula' => 'Fm',
            default => 'P',
        };

        $setNumber = $worksheet->set_number ?: 1;

        return [
            'worksheet_id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'set_number' => $setNumber,
            'short_label' => "{$prefix}{$setNumber}",
            'tier' => $worksheet->tier,
            'tier_label' => $worksheet->tier_label,
            'question_count' => $wrongCount,
            'correction_count' => $wrongCount,
            'delivery_mode' => $worksheet->delivery_mode,
            'status' => 'correction_pending',
            'status_label' => 'CORRECTION',
            'can_assign' => false,
            'can_open' => false,
            'can_redo_wrong' => $wrongCount > 0,
            'is_correction' => true,
            'is_revision' => false,
            'revision_number' => 0,
            'can_start_revision' => false,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $progress
     * @return array{status: string, status_label: string, can_assign: bool, can_open: bool}
     */
    private function statusMeta(?array $progress, bool $assigned): array
    {
        if (! $assigned || ! $progress) {
            return [
                'status' => 'not_assigned',
                'status_label' => 'NOT DONE',
                'can_assign' => true,
                'can_open' => false,
            ];
        }

        $percent = $progress['latest_score_percent'] ?? null;
        $previousPercent = $progress['previous_score_percent'] ?? null;
        $statusDetail = $progress['status_detail'] ?? null;
        $pool = $progress['pool_metrics'] ?? null;
        $completionPct = $progress['completion_pct'] ?? ($pool['completion_pct'] ?? null);

        return match ($progress['status']) {
            'green', 'green-late' => [
                'status' => 'done',
                'status_label' => $this->doneStatusLabel($percent, $previousPercent, $completionPct),
                'can_assign' => true,
                'can_open' => true,
            ],
            'checking' => [
                'status' => 'checking',
                'status_label' => 'CHECKING',
                'can_assign' => false,
                'can_open' => true,
            ],
            'overdue' => [
                'status' => 'overdue',
                'status_label' => $pool && ($pool['pending'] ?? 0) > 0
                    ? 'OVERDUE · '.($statusDetail ?: 'correction left')
                    : 'OVERDUE',
                'can_assign' => true,
                'can_open' => true,
            ],
            default => [
                'status' => ($progress['assignment_status'] ?? null) === SetAssignment::STATUS_IN_PROGRESS
                    || (($pool['pending'] ?? 0) > 0 && ($pool['attempted'] ?? 0) > 0)
                    ? 'in_progress'
                    : 'pending',
                'status_label' => $this->progressStatusLabel($progress, $pool, $statusDetail),
                'can_assign' => false,
                'can_open' => true,
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $progress
     * @param  array<string, mixed>|null  $pool
     */
    private function progressStatusLabel(array $progress, ?array $pool, ?string $statusDetail): string
    {
        if ($pool && ($pool['pool'] ?? 0) > 0 && ($pool['attempted'] ?? 0) > 0) {
            $score = $pool['score_pct'] ?? 0;
            $detail = $statusDetail ?: "{$pool['attempted']}/{$pool['pool']}";

            return "IN PROGRESS ({$detail} · score {$score}%)";
        }

        return ($progress['assignment_status'] ?? null) === SetAssignment::STATUS_IN_PROGRESS
            ? ($statusDetail ? "IN PROGRESS ({$statusDetail})" : 'IN PROGRESS')
            : 'NOT DONE';
    }

    private function doneStatusLabel(?int $percent, ?int $previousPercent, ?int $completionPct = null): string
    {
        $label = $percent !== null ? "DONE({$percent}%)" : 'DONE';

        if ($completionPct !== null && $completionPct < 100) {
            $label = $percent !== null
                ? "SCORE({$percent}%) · {$completionPct}% done"
                : "{$completionPct}% done";
        }

        if ($previousPercent !== null) {
            $label .= " (redo · was {$previousPercent}%)";
        }

        return $label;
    }

    private function bucketForWorksheet(Worksheet $worksheet): string
    {
        if ($worksheet->isFormula()) {
            return 'formula';
        }

        if ($worksheet->isWritten()) {
            return 'written';
        }

        if ($worksheet->isChapterTest()) {
            return 'test';
        }

        if ($this->isFillInBlankSet($worksheet)) {
            return 'fill_blank';
        }

        return 'practice';
    }

    private function isFillInBlankSet(Worksheet $worksheet): bool
    {
        $questions = $worksheet->relationLoaded('questions')
            ? $worksheet->questions
            : $worksheet->questions()->get(['id', 'worksheet_id', 'type']);

        if ($questions->isEmpty()) {
            return false;
        }

        return $questions->every(fn (Question $question) => $question->isFillInBlank());
    }

    /**
     * @param  Collection<int, TextbookChapter>  $textbookChapters
     */
    private function isTextbookWorksheet(Worksheet $worksheet, Collection $textbookChapters): bool
    {
        foreach ($textbookChapters as $textbookChapter) {
            if (in_array($worksheet->id, $textbookChapter->mcqWorksheetIds(), true)) {
                return true;
            }

            if ((int) $textbookChapter->fill_blank_worksheet_id === (int) $worksheet->id) {
                return true;
            }

            if ((int) $textbookChapter->written_worksheet_id === (int) $worksheet->id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, SetAssignment>  $assignments
     */
    private function resolveCurrentAssignment(Collection $assignments): ?SetAssignment
    {
        if ($assignments->isEmpty()) {
            return null;
        }

        $active = $assignments
            ->whereIn('status', [SetAssignment::STATUS_ASSIGNED, SetAssignment::STATUS_IN_PROGRESS])
            ->sortByDesc('id')
            ->first();

        if ($active) {
            return $active;
        }

        return $assignments
            ->where('status', SetAssignment::STATUS_COMPLETED)
            ->sortByDesc('id')
            ->first();
    }
}
