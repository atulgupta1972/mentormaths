<?php

namespace App\Support;

use App\Mail\StudentProgressSummary;
use App\Models\Student;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentProgressMailer
{
    /**
     * @param  array<string, mixed>  $summary
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function send(Student $student, array $summary, ?string $overrideEmail = null): array
    {
        $email = filled($overrideEmail)
            ? $overrideEmail
            : AssignmentMailer::resolveStudentEmail($student);

        if (! $email) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        try {
            Mail::to($email)->send(new StudentProgressSummary($student, $summary));

            return ['sent' => true, 'email' => $email, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send student progress summary email.', [
                'student_id' => $student->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $email, 'error' => 'send_failed'];
        }
    }
}
