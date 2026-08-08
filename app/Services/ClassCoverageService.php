<?php

namespace App\Services;

use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Models\SyllabusChapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassCoverageService
{
    public function __construct(
        private ExamPlanService $examPlanService,
        private StudentChapterSummaryService $chapterSummaryService,
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

            $counts = $summaryById->get($chapter['id'])['counts'] ?? [];
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
            ];
        })->all();

        return [
            'chapters' => $chapters,
            'under_study_chapter_id' => $underStudyId,
            'availability_columns' => $availabilityColumns,
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
            ['key' => 'test', 'label' => 'Test', 'short' => 'Test'],
            ['key' => 'written', 'label' => 'Written', 'short' => 'Writ'],
            ['key' => 'fill_blank', 'label' => 'Fill in blank', 'short' => 'Fill'],
            ['key' => 'formula', 'label' => 'Formula', 'short' => 'Form'],
        ];
    }

    public function markUnderStudy(StudentEnrollment $enrollment, SyllabusChapter $chapter): void
    {
        $this->assertChapterBelongsToEnrollment($enrollment, $chapter);

        $orderedIds = $this->examPlanService
            ->chapterOptionsForEnrollment($enrollment)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $targetIndex = array_search((int) $chapter->id, $orderedIds, true);

        if ($targetIndex === false) {
            throw ValidationException::withMessages([
                'syllabus_chapter_id' => 'This chapter is not part of your class syllabus.',
            ]);
        }

        $earlierIds = array_slice($orderedIds, 0, $targetIndex);
        $now = now();

        DB::transaction(function () use ($enrollment, $chapter, $earlierIds, $now) {
            StudentChapterCoverage::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->where('status', StudentChapterCoverage::STATUS_UNDER_STUDY)
                ->where('syllabus_chapter_id', '!=', $chapter->id)
                ->update([
                    'status' => StudentChapterCoverage::STATUS_STUDIED,
                    'studied_at' => $now,
                    'marked_under_study_at' => null,
                    'updated_at' => $now,
                ]);

            foreach ($earlierIds as $chapterId) {
                StudentChapterCoverage::query()->updateOrCreate(
                    [
                        'student_enrollment_id' => $enrollment->id,
                        'syllabus_chapter_id' => $chapterId,
                    ],
                    [
                        'status' => StudentChapterCoverage::STATUS_STUDIED,
                        'studied_at' => $now,
                        'marked_under_study_at' => null,
                    ],
                );
            }

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
