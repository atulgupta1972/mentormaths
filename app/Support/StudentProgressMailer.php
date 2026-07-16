<?php

namespace App\Support;

use App\Mail\StudentProgressSummary;
use App\Models\Student;
use App\Services\StudentNotificationEmailService;
use App\Services\StudentProgressPdfService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentProgressMailer
{
    /**
     * @param  array<string, mixed>  $summary
     * @param  list<string>|null  $overrideEmails  When set, sends only to these addresses.
     * @return array{sent: bool, emails: list<string>, error: ?string}
     */
    public static function send(
        Student $student,
        array $summary,
        ?array $overrideEmails = null,
        bool $attachPdf = true,
    ): array {
        $emailService = app(StudentNotificationEmailService::class);

        $emails = $overrideEmails !== null
            ? array_values(array_filter($overrideEmails, fn (?string $email) => AssignmentMailer::isDeliverableEmail($email)))
            : $emailService->emailAddressesForStudent($student);

        if ($emails === []) {
            return ['sent' => false, 'emails' => [], 'error' => 'no_email'];
        }

        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();
        $pdfBytes = null;
        $pdfFilename = null;

        if ($attachPdf && config('progress_summary.attach_pdf', true)) {
            $pdfService = app(StudentProgressPdfService::class);
            $pdfBytes = $pdfService->render($summary);
            $pdfFilename = $pdfService->filename($student, $summary);
        }

        try {
            $pending = Mail::to($emails);

            if ($adminEmail && ! self::includesEmail($emails, $adminEmail)) {
                $pending->cc($adminEmail);
            }

            $pending->send(new StudentProgressSummary($student, $summary, $pdfBytes, $pdfFilename));

            return ['sent' => true, 'emails' => $emails, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send student progress summary email.', [
                'student_id' => $student->id,
                'emails' => $emails,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'emails' => $emails, 'error' => 'send_failed'];
        }
    }

    /**
     * @param  list<string>  $emails
     */
    private static function includesEmail(array $emails, string $target): bool
    {
        foreach ($emails as $email) {
            if (strcasecmp($email, $target) === 0) {
                return true;
            }
        }

        return false;
    }
}
