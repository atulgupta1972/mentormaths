<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use App\Services\StudentProgressSummaryService;
use App\Support\AssignmentMailer;
use App\Support\StudentProgressMailer;
use Illuminate\Console\Command;

class SendWeeklyStudentSummaries extends Command
{
    protected $signature = 'students:send-weekly-summaries {--dry-run : Show what would be sent without emailing}';

    protected $description = 'Email weekly progress summaries to students with an email on file';

    public function handle(StudentProgressSummaryService $summaryService): int
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            $this->error('No active academic year.');

            return self::FAILURE;
        }

        $asOf = now()->endOfDay();
        $periodStart = now()->subDays(6)->startOfDay();

        $enrollments = StudentEnrollment::query()
            ->with('student')
            ->where('academic_year_id', $activeYear->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->get();

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($enrollments as $enrollment) {
            $student = $enrollment->student;

            if (! $student) {
                $skipped++;

                continue;
            }

            if (! AssignmentMailer::resolveStudentEmail($student)) {
                $skipped++;

                continue;
            }

            $summary = $summaryService->build($enrollment, $asOf, $periodStart);

            if ($this->option('dry-run')) {
                $this->line("Would send to {$student->name}");

                continue;
            }

            $result = StudentProgressMailer::send($student, $summary);

            if ($result['sent']) {
                $sent++;
                $this->info("Sent to {$student->name} ({$result['email']})");
            } elseif ($result['error'] === 'no_email') {
                $skipped++;
            } else {
                $failed++;
                $this->warn("Failed for {$student->name}");
            }
        }

        $this->info("Weekly summaries: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
