<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use App\Services\StudentNotificationEmailService;
use App\Services\StudentProgressSummaryService;
use App\Support\StudentProgressMailer;
use Illuminate\Console\Command;

class SendWeeklyStudentSummaries extends Command
{
    protected $signature = 'students:send-weekly-summaries {--dry-run : Show what would be sent without emailing}';

    protected $description = 'Email weekly progress summaries (with PDF) to all notify-enabled student/parent emails';

    public function handle(
        StudentProgressSummaryService $summaryService,
        StudentNotificationEmailService $emailService,
    ): int {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            $this->error('No active academic year.');

            return self::FAILURE;
        }

        $asOf = now()->endOfDay();
        $periodStart = now()->subDays(6)->startOfDay();

        $enrollments = StudentEnrollment::query()
            ->with(['student.user:id,email'])
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

            $emails = $emailService->emailAddressesForStudent($student);

            if ($emails === []) {
                $skipped++;

                continue;
            }

            $summary = $summaryService->build($enrollment, $asOf, $periodStart);

            if ($this->option('dry-run')) {
                $this->line("Would send to {$student->name}: ".implode(', ', $emails));

                continue;
            }

            $result = StudentProgressMailer::send($student, $summary);

            if ($result['sent']) {
                $sent++;
                $this->info("Sent to {$student->name} (".implode(', ', $result['emails']).')');
            } elseif ($result['error'] === 'no_email') {
                $skipped++;
            } else {
                $failed++;
                $this->warn("Failed for {$student->name}");
            }
        }

        $this->info("Weekly summaries: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        if (config('progress_summary.whatsapp_driver') === 'manual') {
            $this->comment('WhatsApp: manual mode — use student page to copy message or download PDF for parents.');
        }

        return self::SUCCESS;
    }
}
