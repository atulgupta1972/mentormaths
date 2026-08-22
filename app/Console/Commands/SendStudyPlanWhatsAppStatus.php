<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use App\Services\ClassCoverageService;
use App\Services\StudentProgressSummaryService;
use App\Support\StudentStudyPlanWhatsAppMailer;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Console\Command;

class SendStudyPlanWhatsAppStatus extends Command
{
    protected $signature = 'whatsapp:send-study-plan-status
                            {--dry-run : Show what would be sent without sending}
                            {--force : Send even when no chapters are marked studied / under study}';

    protected $description = 'Send study-plan overall status (dashboard card) via WhatsApp to notify-enabled mobiles';

    public function handle(
        ClassCoverageService $coverageService,
        StudentProgressSummaryService $summaryService,
    ): int {
        if (! config('whatsapp.schedule.study_plan_status_enabled', true)) {
            $this->warn('Study-plan WhatsApp status is disabled (WHATSAPP_STUDY_PLAN_STATUS_ENABLED=false).');

            return self::SUCCESS;
        }

        if (! WhatsAppSender::canAutoSend()) {
            $this->error('WhatsApp auto-send is not configured.');

            return self::FAILURE;
        }

        if (! WhatsAppSender::channelEnabled('study_plan_status')) {
            $this->warn('Study-plan WhatsApp channel is disabled (WHATSAPP_STUDY_PLAN_STATUS=false).');

            return self::SUCCESS;
        }

        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            $this->error('No active academic year.');

            return self::FAILURE;
        }

        $asOf = now();
        $enrollments = StudentEnrollment::query()
            ->with(['student', 'gradeLevel:id,name'])
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

            $performance = $coverageService->studyPlanPerformance($enrollment);

            if ($performance === null && ! $this->option('force')) {
                $skipped++;

                continue;
            }

            $balance = $summaryService->buildBalanceReminder($enrollment, $asOf->copy()->endOfDay());

            $summary = [
                'student_name' => $student->name,
                'class_name' => $enrollment->gradeLevel?->name,
                'as_of_label' => $asOf->timezone(config('app.timezone', 'Asia/Kolkata'))->format('d M Y'),
                'dashboard_url' => route('dashboard'),
                'study_plan' => $performance ?? [
                    'total' => 0,
                    'done' => 0,
                    'completion_pct' => null,
                    'score_pct' => null,
                    'scored_count' => 0,
                    'correction_done' => 0,
                    'correction_pending' => 0,
                    'open_wrongs' => 0,
                    'chapter_count' => 0,
                    'chapter_labels' => [],
                ],
                'pending_count' => (int) ($balance['stats']['pending_count'] ?? 0),
                'overdue_count' => (int) ($balance['stats']['overdue_count'] ?? 0),
            ];

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    'Would send study-plan WhatsApp for %s: %d/%d done (%s%%), score %s%%',
                    $student->name,
                    $summary['study_plan']['done'],
                    $summary['study_plan']['total'],
                    $summary['study_plan']['completion_pct'] ?? '—',
                    $summary['study_plan']['score_pct'] ?? '—',
                ));

                continue;
            }

            $result = StudentStudyPlanWhatsAppMailer::send($student, $summary);

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

        $this->info("Study-plan WhatsApp status: {$sent} sent, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
