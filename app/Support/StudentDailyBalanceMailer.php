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
     * @param  array{to?: list<string>, cc?: list<string>}|null  $recipients
     * @return array{sent: bool, to: list<string>, cc: list<string>, error: ?string}
     */
    public static function send(Student $student, array $summary, ?array $recipients = null): array
    {
        $recipients ??= app(StudentNotificationEmailService::class)->balanceReminderRecipients($student);

        $to = $recipients['to'] ?? [];
        $cc = $recipients['cc'] ?? [];

        if ($to === [] && $cc !== []) {
            $to[] = array_shift($cc);
        }

        if ($to === []) {
            return ['sent' => false, 'to' => [], 'cc' => [], 'error' => 'no_email'];
        }

        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if ($adminEmail && ! self::includesEmail($to, $adminEmail) && ! self::includesEmail($cc, $adminEmail)) {
            $cc[] = $adminEmail;
        }

        try {
            $pending = Mail::to($to);

            if ($cc !== []) {
                $pending->cc($cc);
            }

            $pending->send(new StudentDailyBalanceReminder($student, $summary));

            return ['sent' => true, 'to' => $to, 'cc' => $cc, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send student daily balance reminder email.', [
                'student_id' => $student->id,
                'to' => $to,
                'cc' => $cc,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'to' => $to, 'cc' => $cc, 'error' => 'send_failed'];
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
