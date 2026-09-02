<?php

namespace App\Console\Commands;

use App\Models\AssignmentSumInstance;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Services\AssignmentPoolScore;
use App\Services\ExamPlanService;
use App\Services\StudyPlanMetricsCacheService;
use Illuminate\Console\Command;

class BackfillStudyPlanMetrics extends Command
{
    protected $signature = 'study-plan:backfill-metrics
                            {--enrollment= : Limit to one student_enrollment_id}
                            {--rebuild : Rebuild pool from attempts before caching (slower, fixes drift)}';

    protected $description = 'Cache assignment pool metrics and chapter study-plan performance for faster dashboard loads';

    public function handle(
        AssignmentPoolScore $poolScore,
        StudyPlanMetricsCacheService $metricsCache,
        ExamPlanService $examPlanService,
    ): int {
        $enrollmentId = $this->option('enrollment');
        $rebuild = (bool) $this->option('rebuild');

        $enrollmentQuery = StudentEnrollment::query()
            ->where('status', StudentEnrollment::STATUS_ACTIVE);

        if ($enrollmentId) {
            $enrollmentQuery->whereKey((int) $enrollmentId);
        }

        $enrollments = $enrollmentQuery->get();
        $assignmentCount = 0;
        $chapterCount = 0;

        foreach ($enrollments as $enrollment) {
            $assignmentIds = SetAssignment::query()
                ->where('student_enrollment_id', $enrollment->id)
                ->whereNot('status', SetAssignment::STATUS_CANCELLED)
                ->pluck('id');

            foreach ($assignmentIds as $assignmentId) {
                if (! AssignmentSumInstance::query()->where('set_assignment_id', $assignmentId)->exists()) {
                    continue;
                }

                $assignment = SetAssignment::query()->find($assignmentId);

                if (! $assignment) {
                    continue;
                }

                $metrics = $rebuild
                    ? $poolScore->rebuildFromAttempts($assignment)
                    : $poolScore->metricsForAssignment($assignment);

                if ((int) ($metrics['pool'] ?? 0) > 0) {
                    $metricsCache->cacheAssignmentMetricsOnly($assignment->fresh(), $metrics);
                    $assignmentCount++;
                }
            }

            $chapterIds = $examPlanService->chapterOptionsForEnrollment($enrollment)->pluck('id')->all();

            foreach ($chapterIds as $chapterId) {
                $metricsCache->refreshChapterMetrics($enrollment, (int) $chapterId);
                $chapterCount++;
            }

            $this->line("Enrollment {$enrollment->id}: assignments cached, chapters refreshed.");
        }

        $this->info("Done. Cached {$assignmentCount} assignment(s), refreshed {$chapterCount} chapter row(s).");

        return self::SUCCESS;
    }
}
