<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Models\TextbookChapter;
use App\Models\Worksheet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TextbookChapterPracticeResetService
{
    public function __construct(
        private StudyPlanMetricsCacheService $metrics,
        private TextbookChapterMcqImportService $mcqImport,
    ) {}

    public function attemptCount(TextbookChapter $chapter): int
    {
        $ids = $this->worksheetIds($chapter);

        if ($ids === []) {
            return 0;
        }

        return SetAttempt::query()
            ->whereHas('assignment', fn ($query) => $query->whereIn('worksheet_id', $ids))
            ->count();
    }

    /**
     * Delete published practice for this chapter, including student attempts and scorecard rows,
     * so the same chapter can be imported and published again.
     *
     * @return array{set_count: int, attempt_count: int, codes: list<string>}
     */
    public function reset(TextbookChapter $chapter): array
    {
        $ids = $this->worksheetIds($chapter);

        if ($ids === []) {
            throw new InvalidArgumentException('This chapter has no published sets to remove.');
        }

        return DB::transaction(function () use ($chapter, $ids) {
            $childIds = Worksheet::query()
                ->whereIn('catch_up_parent_worksheet_id', $ids)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $ids = array_values(array_unique([...$ids, ...$childIds]));

            $worksheets = Worksheet::query()
                ->whereIn('id', $ids)
                ->with('questions:id')
                ->get();

            $attemptCount = SetAttempt::query()
                ->whereHas('assignment', fn ($query) => $query->whereIn('worksheet_id', $ids))
                ->count();

            $metricTargets = $this->metricTargets($ids, $chapter->syllabus_chapter_id);
            $questionIds = $worksheets
                ->flatMap(fn (Worksheet $worksheet) => $worksheet->questions->pluck('id'))
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            foreach ($worksheets as $worksheet) {
                if ($worksheet->written_pdf_path) {
                    Storage::disk('public')->delete($worksheet->written_pdf_path);
                }
                Storage::disk('public')->deleteDirectory('written-sheets/'.$worksheet->id);
            }

            $this->mcqImport->deleteStagingDiagrams($chapter);

            $chapter->update([
                'status' => TextbookChapter::STATUS_DRAFT,
                'extraction_items' => null,
                'mcq_set_plan' => null,
                'mcq_worksheet_id' => null,
                'mcq_worksheet_ids' => null,
                'fill_blank_worksheet_id' => null,
                'fill_blank_worksheet_ids' => null,
                'written_worksheet_id' => null,
                'written_worksheet_ids' => null,
                'published_at' => null,
                'extraction_error' => null,
                'extracted_at' => null,
            ]);

            foreach ($worksheets as $worksheet) {
                $worksheet->questions()->detach();
            }

            Worksheet::query()->whereIn('id', $ids)->delete();

            if ($questionIds->isNotEmpty()) {
                $stillLinked = DB::table('worksheet_question')
                    ->whereIn('question_id', $questionIds)
                    ->pluck('question_id')
                    ->map(fn ($id) => (int) $id);

                Question::query()
                    ->whereIn('id', $questionIds->diff($stillLinked))
                    ->get()
                    ->each
                    ->delete();
            }

            ContentUploadTask::query()
                ->where('textbook_chapter_id', $chapter->id)
                ->whereIn('status', [
                    ContentUploadTask::STATUS_PUBLISHED,
                    ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                    ContentUploadTask::STATUS_VERIFIED,
                    ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                    ContentUploadTask::STATUS_UPLOADED,
                ])
                ->update([
                    'status' => ContentUploadTask::STATUS_IN_PROGRESS,
                    'published_at' => null,
                    'published_by' => null,
                ]);

            foreach ($metricTargets as [$enrollmentId, $syllabusChapterId]) {
                $enrollment = StudentEnrollment::query()->find($enrollmentId);
                if ($enrollment) {
                    $this->metrics->refreshChapterMetrics($enrollment, $syllabusChapterId);
                }
            }

            return [
                'set_count' => $worksheets->count(),
                'attempt_count' => $attemptCount,
                'codes' => $worksheets->pluck('set_code')->filter()->values()->all(),
            ];
        });
    }

    /**
     * @return list<int>
     */
    private function worksheetIds(TextbookChapter $chapter): array
    {
        return array_values(array_unique(array_filter(array_map(
            'intval',
            [
                ...$chapter->mcqWorksheetIds(),
                ...$chapter->fillBlankWorksheetIds(),
                ...$chapter->writtenWorksheetIds(),
            ],
        ))));
    }

    /**
     * @param  list<int>  $worksheetIds
     * @return list<array{0: int, 1: int}>
     */
    private function metricTargets(array $worksheetIds, ?int $fallbackChapterId): array
    {
        $assignments = SetAssignment::query()
            ->whereIn('worksheet_id', $worksheetIds)
            ->with([
                'practiceSet.topic:id,syllabus_chapter_id',
                'practiceSet.chapter:id',
            ])
            ->get();

        $pairs = [];

        foreach ($assignments as $assignment) {
            $chapterIds = $this->metrics->syllabusChapterIdsForAssignment($assignment);
            if ($chapterIds === [] && $fallbackChapterId) {
                $chapterIds = [$fallbackChapterId];
            }

            foreach ($chapterIds as $chapterId) {
                $pairs[$assignment->student_enrollment_id.'-'.$chapterId] = [
                    (int) $assignment->student_enrollment_id,
                    (int) $chapterId,
                ];
            }
        }

        return array_values($pairs);
    }
}
