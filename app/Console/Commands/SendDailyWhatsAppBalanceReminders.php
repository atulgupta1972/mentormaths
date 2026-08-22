<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use App\Services\StudentProgressSummaryService;
use App\Support\StudentDailyBalanceWhatsAppMailer;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Console\Command;

class SendDailyWhatsAppBalanceReminders extends Command
{
    protected $signature = 'whatsapp:send-daily-balance-reminders
                            {--dry-run : Show what would be sent without sending}
                            {--force : Send even when a student has nothing pending}';

    protected $description = 'Send daily balance-work WhatsApp reminders to notify-enabled mobiles';

    public function handle(StudentProgressSummaryService $summaryService): int
    {
        if (! config('whatsapp.schedule.daily_balance_enabled', true)) {
            $this->warn('Daily WhatsApp balance reminders are disabled (WHATSAPP_DAILY_BALANCE_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! WhatsAppSender::canAutoSend()) {
            $this->error('WhatsApp auto-send is not configured.');

            return self::FAILURE;
        }

        if (! WhatsAppSender::channelEnabled('daily_balance')) {
            $this->warn('Daily balance WhatsApp channel is disabled (WHATSAPP_DAILY_BALANCE=false).');

            return self::SUCCESS;
        }

        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            $this->error('No active academic year.');

            return self::FAILURE;
        }

        $asOf = now()->endOfDay();

        $enrollments = StudentEnrollment::query()
            ->with(['student'])
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

            $summary = $summaryService->buildBalanceReminder($enrollment, $asOf);

            if (($summary['stats']['balance_count'] ?? 0) === 0 && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Would send WhatsApp balance reminder for %s: %d item(s)',
                    $student->name,
                    $summary['stats']['balance_count'] ?? 0,
                ));

                continue;
            }

            $result = StudentDailyBalanceWhatsAppMailer::send($student, $summary);

            if ($result['sent_count'] > 0) {
                $sent += $result['sent_count'];
                $this->info("WhatsApp sent for {$student->name} ({$result['sent_count']} recipient(s))");
            } elseif ($result['skipped_count'] > 0 || ($result['error'] ?? null) === 'no_recipients') {
                $skipped++;
            }

            if ($result['failed_count'] > 0) {
                $failed += $result['failed_count'];
                $this->warn("WhatsApp failed for {$student->name} ({$result['failed_count']} recipient(s))");
            }
        }

        $this->info("Daily WhatsApp balance reminders: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
