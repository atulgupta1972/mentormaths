<?php

namespace App\Console\Commands;

use App\Models\AssignmentSumInstance;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Services\AssignmentPoolScore;
use App\Services\RevisionAssignmentService;
use Illuminate\Console\Command;

class RefreshAssignmentPoolMetricsCommand extends Command
{
    protected $signature = 'assignments:refresh-pool-metrics
                            {--enrollment= : Limit to one student enrollment id}
                            {--rebuild : Rebuild sum instances from attempts when the pool is missing}';

    protected $description = 'Save per-set pool scores onto assignments (run after deploy; study plan then only updates on new tests)';

    public function handle(
        AssignmentPoolScore $poolScore,
        RevisionAssignmentService $revisions,
    ): int {
        $enrollmentId = $this->option('enrollment');
        $enrollmentId = is_numeric($enrollmentId) ? (int) $enrollmentId : null;
        $rebuildMissing = (bool) $this->option('rebuild');

        $query = SetAssignment::query()
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->when($enrollmentId, fn ($q) => $q->where('student_enrollment_id', $enrollmentId))
            ->orderBy('id');

        $refreshed = 0;
        $rebuilt = 0;
        $revisionsOpened = 0;

        $query->chunkById(50, function ($assignments) use (
            $poolScore,
            $revisions,
            $rebuildMissing,
            &$refreshed,
            &$rebuilt,
            &$revisionsOpened,
        ) {
            $assignments->load(['enrollment:id,student_id', 'practiceSet.questions:id']);

            foreach ($assignments as $assignment) {
                if (! $assignment->practiceSet) {
                    continue;
                }

                $hasInstances = AssignmentSumInstance::query()
                    ->where('set_assignment_id', $assignment->id)
                    ->exists();
                $hasSubmitted = SetAttempt::query()
                    ->where('set_assignment_id', $assignment->id)
                    ->where('status', SetAttempt::STATUS_SUBMITTED)
                    ->exists();

                if (! $hasInstances) {
                    if ($rebuildMissing && $hasSubmitted) {
                        $poolScore->rebuildFromAttempts($assignment);
                        $rebuilt++;
                    }

                    continue;
                }

                $poolScore->metricsForAssignment($assignment, refresh: true);
                $refreshed++;

                if ($assignment->isOriginalLearning()) {
                    $revision = $revisions->ensureFirstRevisionIfReady($assignment->fresh());
                    if ($revision && $revision->wasRecentlyCreated) {
                        $revisionsOpened++;
                    }
                }
            }
        });

        $this->table(
            ['Result', 'Count'],
            [
                ['Snapshots from existing pool rows', (string) $refreshed],
                ['Pools rebuilt from attempts', (string) $rebuilt],
                ['Rev 1 opened for fully corrected originals', (string) $revisionsOpened],
            ],
        );

        $this->info('Study plan pages can now read saved scores instead of rebuilding every set.');

        return self::SUCCESS;
    }
}
