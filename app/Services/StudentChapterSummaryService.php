<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Models\SyllabusChapter;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use App\Support\AssignmentProgress;
use App\Support\PracticeSetScope;
use App\Support\WorksheetDeliveryMode;
use Illuminate\Support\Collection;

class StudentChapterSummaryService
{
    public function __construct(
        private ExamPlanService $examPlanService,
    ) {}

    /**
     * @return array{book_columns: list<array<string, mixed>>, chapters: list<array<string, mixed>>}
     */
    public function forEnrollment(StudentEnrollment $enrollment): array
    {
        $syllabusVersion = $this->examPlanService->syllabusVersionForEnrollment($enrollment);

        if (! $syllabusVersion) {
            return ['book_columns' => [], 'chapters' => []];
        }

        $chapters = SyllabusChapter::query()
            ->where('syllabus_version_id', $syllabusVersion->id)
            ->orderBy('sort_order')
            ->get(['id', 'chapter_number', 'name', 'sort_order']);

        if ($chapters->isEmpty()) {
            return ['book_columns' => [], 'chapters' => []];
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

        $textbookColumns = $this->textbookColumnsForGrade($enrollment->grade_level_id);
        $textbookChapters = $this->textbookChaptersForSyllabus($chapterIds, $textbookColumns);
        $textbookWorksheetIds = $textbookChapters
            ->flatMap(fn (TextbookChapter $row) => array_merge(
                $row->mcqWorksheetIds(),
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

        $worksheetsById = $worksheets->keyBy('id');
        $worksheetsByChapter = $this->groupWorksheetsByChapter($worksheets, $chapterIds);

        $chapterRows = $chapters->map(function (SyllabusChapter $chapter) use (
            $worksheetsByChapter,
            $worksheetsById,
            $textbookChapters,
            $textbookColumns,
            $assignmentsByWorksheet,
        ) {
            $chapterWorksheets = $worksheetsByChapter->get($chapter->id, collect());
            $chapterTextbookRows = $textbookChapters->where('syllabus_chapter_id', $chapter->id);

            $practice = [];
            $tests = [];
            $written = [];
            $fillBlank = [];
            $books = [];

            foreach ($chapterWorksheets as $worksheet) {
                if ($this->isTextbookWorksheet($worksheet, $chapterTextbookRows)) {
                    continue;
                }

                if ($worksheet->isCatchUp() || $worksheet->isFormula()) {
                    continue;
                }

                $item = $this->buildSetItem($worksheet, $assignmentsByWorksheet);
                $bucket = $this->bucketForWorksheet($worksheet);

                match ($bucket) {
                    'test' => $tests[] = $item,
                    'written' => $written[] = $item,
                    'fill_blank' => $fillBlank[] = $item,
                    default => $practice[] = $item,
                };
            }

            foreach ($textbookColumns as $bookColumn) {
                $bookItems = [];
                $textbookChapter = $chapterTextbookRows
                    ->first(fn (TextbookChapter $row) => (int) $row->textbook_id === (int) $bookColumn['id']);

                if ($textbookChapter) {
                    foreach ($textbookChapter->mcqWorksheetIds() as $worksheetId) {
                        $worksheet = $worksheetsById->get($worksheetId);

                        if ($worksheet) {
                            $bookItems[] = $this->buildSetItem($worksheet, $assignmentsByWorksheet, 'B');
                        }
                    }

                    if ($textbookChapter->written_worksheet_id) {
                        $worksheet = $worksheetsById->get((int) $textbookChapter->written_worksheet_id);

                        if ($worksheet) {
                            $bookItems[] = $this->buildSetItem($worksheet, $assignmentsByWorksheet, 'B');
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
                    'books' => collect($books)->map(fn (array $items) => count($items))->all(),
                ],
                'items' => [
                    'practice' => $practice,
                    'test' => $tests,
                    'written' => $written,
                    'fill_blank' => $fillBlank,
                    'books' => $books,
                ],
            ];
        })->values()->all();

        return [
            'book_columns' => $textbookColumns,
            'chapters' => $chapterRows,
        ];
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
     * @param  Collection<int, Collection<int, SetAssignment>>  $assignmentsByWorksheet
     * @return array<string, mixed>
     */
    private function buildSetItem(
        Worksheet $worksheet,
        Collection $assignmentsByWorksheet,
        ?string $prefixOverride = null,
    ): array {
        $assignment = $this->resolveCurrentAssignment(
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
            default => 'P',
        };

        $setNumber = $worksheet->set_number ?: 1;
        $shortLabel = "{$prefix}{$setNumber}";
        $statusMeta = $this->statusMeta($progress, $assignment !== null);

        return [
            'worksheet_id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'set_number' => $setNumber,
            'short_label' => $shortLabel,
            'question_count' => (int) ($worksheet->questions_count ?? 0),
            'delivery_mode' => $worksheet->delivery_mode ?? WorksheetDeliveryMode::ONLINE,
            'assignment_id' => $assignment?->id,
            'latest_attempt_id' => $progress['latest_attempt_id'] ?? null,
            'status' => $statusMeta['status'],
            'status_label' => $statusMeta['status_label'],
            'can_assign' => $statusMeta['can_assign'],
            'can_open' => $statusMeta['can_open'],
            'latest_score_percent' => $progress['latest_score_percent'] ?? null,
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

        return match ($progress['status']) {
            'green', 'green-late' => [
                'status' => 'done',
                'status_label' => $percent !== null ? "DONE({$percent}%)" : 'DONE',
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
                'status_label' => 'OVERDUE',
                'can_assign' => true,
                'can_open' => true,
            ],
            default => [
                'status' => ($progress['assignment_status'] ?? null) === SetAssignment::STATUS_IN_PROGRESS
                    ? 'in_progress'
                    : 'pending',
                'status_label' => ($progress['assignment_status'] ?? null) === SetAssignment::STATUS_IN_PROGRESS
                    ? 'IN PROGRESS'
                    : 'NOT DONE',
                'can_assign' => false,
                'can_open' => true,
            ],
        };
    }

    private function bucketForWorksheet(Worksheet $worksheet): string
    {
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
