<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Support\Collection;

class AdminChapterContentService
{
    /**
     * @return array{
     *     grade_levels: list<array<string, mixed>>,
     *     boards_by_grade: array<int, list<array<string, mixed>>>,
     *     selected_grade_level_id: int|null,
     *     selected_board_id: int|null
     * }
     */
    public function filterOptions(
        AcademicYear $year,
        ?int $selectedGradeLevelId = null,
        ?int $selectedBoardId = null,
    ): array {
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $maths) {
            return [
                'grade_levels' => [],
                'boards_by_grade' => [],
                'selected_grade_level_id' => null,
                'selected_board_id' => null,
            ];
        }

        $versions = SyllabusVersion::query()
            ->where('academic_year_id', $year->id)
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

        $selectedGradeLevelId = $selectedGradeLevelId ?? ($gradeLevels[0]['id'] ?? null);
        $boardsForGrade = $boardsByGrade[$selectedGradeLevelId] ?? [];
        $selectedBoardId = $selectedBoardId ?? ($boardsForGrade[0]['id'] ?? null);

        $boardIdsForGrade = collect($boardsForGrade)->pluck('id')->all();

        if ($boardIdsForGrade !== [] && $selectedBoardId && ! in_array($selectedBoardId, $boardIdsForGrade, true)) {
            $selectedBoardId = $boardIdsForGrade[0];
        }

        return [
            'grade_levels' => $gradeLevels,
            'boards_by_grade' => $boardsByGrade,
            'selected_grade_level_id' => $selectedGradeLevelId,
            'selected_board_id' => $selectedBoardId,
        ];
    }

    /**
     * @return array{
     *     book_columns: list<array<string, mixed>>,
     *     chapters: list<array<string, mixed>>,
     *     context: array<string, mixed>
     * }
     */
    public function forClassAndBoard(
        AcademicYear $year,
        int $gradeLevelId,
        int $boardId,
    ): array {
        $syllabusVersion = $this->resolveSyllabusVersion($year, $gradeLevelId, $boardId);

        $context = [
            'selected_grade_level_id' => $gradeLevelId,
            'selected_board_id' => $boardId,
            'selected_grade_name' => $syllabusVersion?->gradeLevel?->name,
            'selected_board_name' => $syllabusVersion?->board?->name,
        ];

        if (! $syllabusVersion) {
            return [
                'book_columns' => [],
                'chapters' => [],
                'context' => $context,
            ];
        }

        $syllabusVersion->loadMissing(['gradeLevel:id,name', 'board:id,name,code']);

        $chapters = SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabusVersion->id)
            ->orderBy('sort_order')
            ->get(['id', 'chapter_number', 'name', 'sort_order']);

        if ($chapters->isEmpty()) {
            return [
                'book_columns' => $this->textbookColumnsForGrade($gradeLevelId),
                'chapters' => [],
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
            ->whereIn('status', [Worksheet::STATUS_PUBLISHED, Worksheet::STATUS_DRAFT])
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
            ->flatMap(fn (TextbookChapter $row) => $row->allWorksheetIds())
            ->unique()
            ->values();

        $missingTextbookWorksheets = $textbookWorksheetIds
            ->diff($worksheets->pluck('id'))
            ->values();

        if ($missingTextbookWorksheets->isNotEmpty()) {
            $worksheets = $worksheets->merge(
                Worksheet::query()
                    ->where('status', Worksheet::STATUS_PUBLISHED)
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

        $worksheetsById = $worksheets->keyBy('id');
        $worksheetsByChapter = $this->groupWorksheetsByChapter($worksheets, $chapterIds);

        $chapterRows = $chapters->map(function (SyllabusChapter $chapter) use (
            $worksheetsByChapter,
            $worksheetsById,
            $textbookChapters,
            $textbookColumns,
        ) {
            $chapterWorksheets = $worksheetsByChapter->get($chapter->id, collect());
            $chapterTextbookRows = $textbookChapters->where('syllabus_chapter_id', $chapter->id);

            $practice = [];
            $tests = [];
            $written = [];
            $fillBlank = [];
            $formula = [];
            $books = [];

            foreach ($chapterWorksheets as $worksheet) {
                if ($this->isTextbookWorksheet($worksheet, $chapterTextbookRows)) {
                    continue;
                }

                if ($worksheet->isCatchUp()) {
                    continue;
                }

                $item = $this->buildSetItem($worksheet);
                $bucket = $this->bucketForWorksheet($worksheet);

                match ($bucket) {
                    'test' => $tests[] = $item,
                    'written' => $written[] = $item,
                    'fill_blank' => $fillBlank[] = $item,
                    'formula' => $formula[] = $item,
                    default => $practice[] = $item,
                };
            }

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

                            $item = $this->buildSetItem($worksheet, 'B');
                            $item['part'] = $part['part'];
                            $item['kind'] = $kind;
                            $item['kind_label'] = $kindLabel;
                            $item['textbook_id'] = (int) $bookColumn['id'];
                            $item['textbook_name'] = (string) ($bookColumn['name'] ?? $bookColumn['label'] ?? 'Book');
                            $bookItems[] = $item;
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
                    'test' => count($tests),
                    'written' => count($written),
                    'fill_blank' => count($fillBlank),
                    'formula' => count($formula),
                    'books' => collect($books)->map(fn (array $items) => count($items))->all(),
                ],
                'items' => [
                    'practice' => $practice,
                    'test' => $tests,
                    'written' => $written,
                    'fill_blank' => $fillBlank,
                    'formula' => $formula,
                    'books' => $books,
                ],
            ];
        })->values()->all();

        return [
            'book_columns' => $textbookColumns,
            'chapters' => $chapterRows,
            'context' => array_merge($context, [
                'selected_grade_name' => $syllabusVersion->gradeLevel?->name,
                'selected_board_name' => $syllabusVersion->board?->name,
            ]),
        ];
    }

    private function resolveSyllabusVersion(
        AcademicYear $year,
        int $gradeLevelId,
        int $boardId,
    ): ?SyllabusVersion {
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $maths) {
            return null;
        }

        return SyllabusVersion::query()
            ->where('academic_year_id', $year->id)
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
     * @return array<string, mixed>
     */
    private function buildSetItem(Worksheet $worksheet, ?string $prefixOverride = null): array
    {
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
        $published = $worksheet->status === Worksheet::STATUS_PUBLISHED;

        return [
            'worksheet_id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'set_number' => $setNumber,
            'short_label' => $shortLabel,
            'tier' => $worksheet->tier,
            'tier_label' => $worksheet->tier_label,
            'question_count' => (int) ($worksheet->questions_count ?? 0),
            'delivery_mode' => $worksheet->delivery_mode ?? WorksheetDeliveryMode::ONLINE,
            'status' => $published ? 'published' : 'draft',
            'status_label' => $published ? 'PUBLISHED' : 'DRAFT',
            'admin_url' => route('admin.questions.sets.show', $worksheet),
        ];
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
            if (in_array($worksheet->id, $textbookChapter->allWorksheetIds(), true)) {
                return true;
            }
        }

        return false;
    }
}
