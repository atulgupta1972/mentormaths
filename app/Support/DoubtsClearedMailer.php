<?php

namespace App\Support;

use App\Mail\DoubtsCleared;
use App\Models\Student;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class DoubtsClearedMailer
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function send(Student $student, array $items): array
    {
        if ($items === []) {
            return ['sent' => false, 'email' => null, 'error' => 'no_items'];
        }

        $email = AssignmentMailer::resolveStudentEmail($student);

        if (! $email) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        try {
            $pending = Mail::to($email);

            if ($adminEmail && strcasecmp($adminEmail, $email) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send(new DoubtsCleared($student, $items));

            return ['sent' => true, 'email' => $email, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send doubts cleared email.', [
                'student_id' => $student->id,
                'email' => $email,
                'item_count' => count($items),
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $email, 'error' => 'send_failed'];
        }
    }
}
