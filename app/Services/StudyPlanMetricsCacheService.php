<?php

namespace App\Services;

use App\Models\AssignmentSumInstance;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\StudentChapterMetric;
use App\Models\StudentEnrollment;
use App\Support\SumPoolAggregate;
use Carbon\Carbon;

class StudyPlanMetricsCacheService
{
    public function __construct(
        private StudentChapterSummaryService $chapterSummary,
    ) {}

    /**
     * @param  array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
     *     pending_remedial: int,
     *     wrong: int,
     *     completion_pct: int|null,
     *     score_pct: int|null
     * }  $metrics
     */
    public function persistAssignmentPoolMetrics(SetAssignment $assignment, array $metrics): void
    {
        $this->cacheAssignmentMetricsOnly($assignment, $metrics);

        $assignment->loadMissing('enrollment');
        $enrollment = $assignment->enrollment;

        if (! $enrollment) {
            return;
        }

        foreach ($this->syllabusChapterIdsForAssignment($assignment) as $chapterId) {
            $this->refreshChapterMetrics($enrollment, $chapterId);
        }
    }

    /**
     * @param  array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
     *     pending_remedial: int,
     *     wrong: int,
     *     completion_pct: int|null,
     *     score_pct: int|null
     * }  $metrics
     */
    public function cacheAssignmentMetricsOnly(SetAssignment $assignment, array $metrics): void
    {
        $assignment->forceFill([
            'cached_pool_metrics' => $metrics,
            'cached_metrics_at' => now(),
        ])->save();
    }

    /**
     * Read cached pool metrics for dashboard display — never rebuilds from attempts.
     *
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
     *     pending_remedial: int,
     *     wrong: int,
     *     completion_pct: int|null,
     *     score_pct: int|null
     * }|null
     */
    public function metricsForAssignmentRead(SetAssignment $assignment): ?array
    {
        $hasSubmittedAttempts = SetAttempt::query()
            ->where('set_assignment_id', $assignment->id)
            ->where('status', SetAttempt::STATUS_SUBMITTED)
            ->exists();

        $hasPoolRows = AssignmentSumInstance::query()
            ->where('set_assignment_id', $assignment->id)
            ->exists();

        if (! $hasSubmittedAttempts && ! $hasPoolRows) {
            return null;
        }

        if (! $hasPoolRows) {
            $metrics = app(AssignmentPoolScore::class)->rebuildFromAttempts($assignment);

            return (int) ($metrics['pool'] ?? 0) > 0 ? $metrics : null;
        }

        $cached = $assignment->cached_pool_metrics;
        $needsRebuild = $this->shouldRebuildPoolMetrics($assignment, $cached);

        if (is_array($cached) && (int) ($cached['pool'] ?? 0) > 0 && ! $needsRebuild) {
            return $cached;
        }

        $poolScore = app(AssignmentPoolScore::class);
        $metrics = $needsRebuild
            ? $poolScore->rebuildFromAttempts($assignment)
            : $poolScore->metricsForAssignment($assignment);

        if ((int) ($metrics['pool'] ?? 0) <= 0) {
            return null;
        }

        if (! is_array($cached) || $this->poolMetricsDiffer($cached, $metrics)) {
            $this->cacheAssignmentMetricsOnly($assignment, $metrics);
        }

        return $metrics;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function poolMetricsDiffer(array $left, array $right): bool
    {
        foreach (['pool', 'attempted', 'correct', 'pending', 'pending_remedial', 'completion_pct', 'score_pct'] as $key) {
            if ((int) ($left[$key] ?? 0) !== (int) ($right[$key] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $cached
     */
    private function shouldRebuildPoolMetrics(SetAssignment $assignment, ?array $cached): bool
    {
        if (! is_array($cached) || (int) ($cached['pool'] ?? 0) <= 0) {
            return SetAttempt::query()
                ->where('set_assignment_id', $assignment->id)
                ->where('status', SetAttempt::STATUS_SUBMITTED)
                ->exists();
        }

        $latestCompletedAt = SetAttempt::query()
            ->where('set_assignment_id', $assignment->id)
            ->where('status', SetAttempt::STATUS_SUBMITTED)
            ->max('completed_at');

        if ($latestCompletedAt && (
            ! $assignment->cached_metrics_at
            || Carbon::parse($latestCompletedAt)->gt($assignment->cached_metrics_at)
        )) {
            return true;
        }

        return false;
    }

    public function refreshChapterMetrics(StudentEnrollment $enrollment, int $syllabusChapterId): void
    {
        $buckets = $this->chapterSummary->chapterItemBucketsForMetrics($enrollment, $syllabusChapterId);
        $items = $this->flattenSummaryChapterItems($buckets);
        $performance = $this->performanceFromItems($items);

        StudentChapterMetric::query()->updateOrCreate(
            [
                'student_enrollment_id' => $enrollment->id,
                'syllabus_chapter_id' => $syllabusChapterId,
            ],
            [
                'performance' => $performance,
                'metrics_updated_at' => now(),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public function performanceFromItems(array $items): array
    {
        $main = array_values(array_filter(
            $items,
            fn (array $item) => ! ($item['is_correction'] ?? false) && ! ($item['is_revision'] ?? false),
        ));
        $revisions = array_values(array_filter($items, fn (array $item) => (bool) ($item['is_revision'] ?? false)));
        $corrections = array_values(array_filter($items, fn (array $item) => (bool) ($item['is_correction'] ?? false)));

        $mainAgg = SumPoolAggregate::fromItems($main);
        $revisionAgg = SumPoolAggregate::fromItems($revisions);

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
            'completionPct' => $mainAgg['completion_pct'],
            'scorePct' => $mainAgg['score_pct'],
            'scoredCount' => $mainAgg['correct'],
            'setTotal' => $mainAgg['set_total'],
            'setDone' => $mainAgg['set_done'],
            'revisionTotal' => $revisionAgg['pool'],
            'revisionDone' => $revisionAgg['attempted'],
            'revisionCorrect' => $revisionAgg['correct'],
            'revisionCompletionPct' => $revisionAgg['completion_pct'],
            'revisionScorePct' => $revisionAgg['score_pct'],
            'revisionScoredCount' => $revisionAgg['correct'],
            'correctionDone' => $correctionDone,
            'correctionPending' => $correctionPending,
            'openWrongs' => $openWrongs,
            'revisionPending' => max(0, $revisionAgg['pool'] - $revisionAgg['attempted']) + $openWrongs,
        ];
    }

    /**
     * @return list<int>
     */
    public function syllabusChapterIdsForAssignment(SetAssignment $assignment): array
    {
        $assignment->loadMissing([
            'practiceSet.topic:id,syllabus_chapter_id',
            'practiceSet.chapter:id',
        ]);

        $ids = [];

        if ($assignment->effective_syllabus_chapter_id) {
            $ids[] = (int) $assignment->effective_syllabus_chapter_id;
        }

        $worksheet = $assignment->practiceSet;

        if ($worksheet?->isChapterScope() && $worksheet->syllabus_chapter_id) {
            $ids[] = (int) $worksheet->syllabus_chapter_id;
        } elseif ($worksheet?->topic?->syllabus_chapter_id) {
            $ids[] = (int) $worksheet->topic->syllabus_chapter_id;
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * @param  array<string, mixed>  $buckets
     * @return list<array<string, mixed>>
     */
    public function flattenSummaryChapterItems(array $buckets): array
    {
        $collected = [];

        foreach (['practice', 'practice_correction', 'test', 'written', 'fill_blank', 'formula'] as $key) {
            foreach ($buckets[$key] ?? [] as $item) {
                if (is_array($item)) {
                    $collected[] = $item;
                }
            }
        }

        foreach ($buckets['books'] ?? [] as $bookItems) {
            if (! is_array($bookItems)) {
                continue;
            }

            foreach ($bookItems as $item) {
                if (is_array($item)) {
                    $collected[] = $item;
                }
            }
        }

        foreach ($buckets['revisions'] ?? [] as $item) {
            if (is_array($item)) {
                $collected[] = array_merge($item, ['is_revision' => true]);
            }
        }

        return $collected;
    }
}
