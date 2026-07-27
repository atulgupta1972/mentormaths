<?php

namespace App\Support;

use App\Mail\StudentDailyBalanceReminder;
use App\Models\Student;
use App\Services\StudentNotificationEmailService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentDailyBalanceMailer
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array{sent: bool, emails: list<string>, error: ?string}
     */
    public static function send(Student $student, array $summary): array
    {
        $emailService = app(StudentNotificationEmailService::class);
        $emails = $emailService->emailAddressesForStudent($student);

        if ($emails === []) {
            return ['sent' => false, 'emails' => [], 'error' => 'no_email'];
        }

        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        try {
            $pending = Mail::to($emails);

            if ($adminEmail && ! self::includesEmail($emails, $adminEmail)) {
                $pending->cc($adminEmail);
            }

            $pending->send(new StudentDailyBalanceReminder($student, $summary));

            return ['sent' => true, 'emails' => $emails, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send student daily balance reminder email.', [
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
