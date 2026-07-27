<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use App\Services\StudentNotificationEmailService;
use App\Services\StudentProgressSummaryService;
use App\Support\StudentDailyBalanceMailer;
use Illuminate\Console\Command;

class SendDailyStudentBalanceReminders extends Command
{
    protected $signature = 'students:send-daily-balance-reminders
                            {--dry-run : Show what would be sent without emailing}
                            {--force : Send even when a student has nothing pending}';

    protected $description = 'Email daily balance-work reminders (practice, test, written, corrections still to do)';

    public function handle(
        StudentProgressSummaryService $summaryService,
        StudentNotificationEmailService $emailService,
    ): int {
        if (! config('progress_summary.daily_balance_enabled', true)) {
            $this->warn('Daily balance emails are disabled (DAILY_BALANCE_EMAIL_ENABLED=false).');

            return self::SUCCESS;
        }

        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            $this->error('No active academic year.');

            return self::FAILURE;
        }

        $asOf = now()->endOfDay();

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

            $recipients = $emailService->balanceReminderRecipients($student);

            if ($recipients['to'] === [] && $recipients['cc'] === []) {
                $skipped++;

                continue;
            }

            $summary = $summaryService->buildBalanceReminder($enrollment, $asOf);

            if (($summary['stats']['balance_count'] ?? 0) === 0 && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $allRecipients = array_merge($recipients['to'], $recipients['cc']);
                $this->line(sprintf(
                    'Would send to %s: %d item(s) (to: %s%s)',
                    $student->name,
                    $summary['stats']['balance_count'],
                    implode(', ', $recipients['to']),
                    $recipients['cc'] !== [] ? '; cc: '.implode(', ', $recipients['cc']) : '',
                ));

                continue;
            }

            $result = StudentDailyBalanceMailer::send($student, $summary, $recipients);

            if ($result['sent']) {
                $sent++;
                $recipientLine = implode(', ', $result['to']);
                if ($result['cc'] !== []) {
                    $recipientLine .= ' (cc: '.implode(', ', $result['cc']).')';
                }
                $this->info("Sent to {$student->name} ({$recipientLine})");
            } elseif ($result['error'] === 'no_email') {
                $skipped++;
            } else {
                $failed++;
                $this->warn("Failed for {$student->name}");
            }
        }

        $this->info("Daily balance reminders: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
