<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use App\Services\BasicsDrillReportService;
use App\Services\FormulaDrillReportService;
use App\Services\StudentProgressSummaryService;
use App\Support\StudentProgressWhatsAppMailer;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Console\Command;

class SendWeeklyWhatsAppSummaries extends Command
{
    protected $signature = 'whatsapp:send-weekly-summaries {--dry-run : Show what would be sent without sending}';

    protected $description = 'Send weekly progress summary WhatsApp messages to notify-enabled mobiles';

    public function handle(
        StudentProgressSummaryService $summaryService,
        FormulaDrillReportService $formulaDrillReport,
        BasicsDrillReportService $basicsDrillReport,
    ): int {
        if (! config('whatsapp.schedule.weekly_summary_enabled', true)) {
            $this->warn('Weekly WhatsApp summaries are disabled (WHATSAPP_WEEKLY_SUMMARY_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! WhatsAppSender::canAutoSend()) {
            $this->error('WhatsApp auto-send is not configured.');

            return self::FAILURE;
        }

        if (! WhatsAppSender::channelEnabled('progress_summary')) {
            $this->warn('Weekly WhatsApp channel is disabled (WHATSAPP_PROGRESS_SUMMARY=false).');

            return self::SUCCESS;
        }

        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            $this->error('No active academic year.');

            return self::FAILURE;
        }

        $asOf = now()->endOfDay();
        $periodStart = now()->subDays(6)->startOfDay();

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

            $summary = $summaryService->build($enrollment, $asOf, $periodStart);
            $summary['formula_drill'] = $formulaDrillReport->summaryForStudent($student);
            $summary['basics_drill'] = $basicsDrillReport->summaryForStudent($student);

            if ($this->option('dry-run')) {
                $this->line("Would send WhatsApp summary for {$student->name}");

                continue;
            }

            $result = StudentProgressWhatsAppMailer::send($student, $summary);

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

        $this->info("Weekly WhatsApp summaries: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
