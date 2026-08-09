<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\StudentDailyBalanceMailer;
use App\Support\StudentDailyBalanceWhatsAppMailer;
use App\Support\WhatsApp\WhatsAppSender;
use Carbon\Carbon;

class PendingWorkEmailService
{
    public function __construct(
        private StudentProgressSummaryService $summaryService,
        private StudentNotificationEmailService $emailService,
    ) {}

    /**
     * @return array{sent: bool, to: list<string>, cc: list<string>, error: ?string, balance_count: int}
     */
    public function sendToEnrollment(
        StudentEnrollment $enrollment,
        ?Carbon $asOf = null,
        bool $skipIfEmpty = true,
    ): array {
        $student = $enrollment->student;

        if (! $student) {
            return $this->result(false, [], [], 'no_student', 0);
        }

        $recipients = $this->emailService->balanceReminderRecipients($student);

        if ($recipients['to'] === [] && $recipients['cc'] === []) {
            return $this->result(false, [], [], 'no_email', 0);
        }

        $asOf = ($asOf ?? now())->copy()->endOfDay();
        $summary = $this->summaryService->buildBalanceReminder($enrollment, $asOf);
        $balanceCount = (int) ($summary['stats']['balance_count'] ?? 0);

        if ($skipIfEmpty && $balanceCount === 0) {
            return $this->result(false, $recipients['to'], $recipients['cc'], 'no_work', 0);
        }

        $mailResult = StudentDailyBalanceMailer::send($student, $summary, $recipients);

        if (WhatsAppSender::canAutoSend() && WhatsAppSender::channelEnabled('pending_work')) {
            StudentDailyBalanceWhatsAppMailer::send($student, $summary);
        }

        return $this->result(
            $mailResult['sent'],
            $mailResult['to'] ?? $recipients['to'],
            $mailResult['cc'] ?? $recipients['cc'],
            $mailResult['error'],
            $balanceCount,
        );
    }

    /**
     * @return array{sent: int, skipped: int, failed: int, no_work: int}
     */
    public function sendToAll(?int $gradeLevelId = null, bool $skipIfEmpty = true): array
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'no_work' => 0];
        }

        $enrollments = StudentEnrollment::query()
            ->with(['student.user:id,email'])
            ->where('academic_year_id', $activeYear->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->when($gradeLevelId, fn ($query) => $query->where('grade_level_id', $gradeLevelId))
            ->get();

        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'no_work' => 0];

        foreach ($enrollments as $enrollment) {
            $result = $this->sendToEnrollment($enrollment, null, $skipIfEmpty);

            if ($result['sent']) {
                $counts['sent']++;
            } elseif ($result['error'] === 'no_work') {
                $counts['no_work']++;
            } elseif ($result['error'] === 'no_email' || $result['error'] === 'no_student') {
                $counts['skipped']++;
            } else {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{sent: bool, to: list<string>, cc: list<string>, error: ?string, balance_count: int}
     */
    public function sendToStudent(Student $student, bool $skipIfEmpty = true): array
    {
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return $this->result(false, [], [], 'no_enrollment', 0);
        }

        return $this->sendToEnrollment($enrollment, null, $skipIfEmpty);
    }

    /**
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @return array{sent: bool, to: list<string>, cc: list<string>, error: ?string, balance_count: int}
     */
    private function result(
        bool $sent,
        array $to,
        array $cc,
        ?string $error,
        int $balanceCount,
    ): array {
        return [
            'sent' => $sent,
            'to' => $to,
            'cc' => $cc,
            'error' => $error,
            'balance_count' => $balanceCount,
        ];
    }
}
