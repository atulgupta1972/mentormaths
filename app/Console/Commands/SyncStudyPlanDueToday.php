<?php

namespace App\Console\Commands;

use App\Services\ClassCoverageService;
use Illuminate\Console\Command;

class SyncStudyPlanDueToday extends Command
{
    protected $signature = 'study-plan:assign-due-today
                            {--enrollment= : Limit to one student_enrollment_id}';

    protected $description = 'For chapters already marked Studied / Under study, assign remaining sets with due date = today';

    public function handle(ClassCoverageService $coverageService): int
    {
        $enrollmentId = $this->option('enrollment');
        $stats = $coverageService->syncDueTodayForAllMarkedChapters(
            $enrollmentId !== null ? (int) $enrollmentId : null,
        );

        $this->info(sprintf(
            'Synced %d enrollment(s), %d chapter mark(s), %d assignment update(s).',
            $stats['enrollments'],
            $stats['chapters'],
            $stats['assignments'],
        ));

        return self::SUCCESS;
    }
}
