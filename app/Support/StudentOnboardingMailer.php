<?php

namespace App\Support;

use App\Mail\StudentOnboardingProcess;
use App\Models\Student;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class StudentOnboardingMailer
{
    /**
     * First welcome email with the complete working process (sent once at signup).
     *
     * @return array{sent: bool, to: list<string>, error: ?string}
     */
    public static function send(Student $student, string $loginEmail, ?string $extraEmail = null): array
    {
        $emails = collect([$loginEmail, $extraEmail, $student->parent1_email])
            ->filter(fn ($e) => AssignmentMailer::isDeliverableEmail($e))
            ->unique(fn ($e) => strtolower((string) $e))
            ->values()
            ->all();

        if ($emails === []) {
            return ['sent' => false, 'to' => [], 'error' => 'no_email'];
        }

        $payload = [
            'student_name' => $student->name,
            'login_url' => route('login'),
            'dashboard_url' => route('dashboard'),
            'study_plan_url' => route('student.school-study-plan.show'),
            'register_hint' => false,
        ];

        $sentTo = [];

        foreach ($emails as $email) {
            try {
                Mail::to($email)->send(new StudentOnboardingProcess($student, $payload));
                $sentTo[] = $email;
            } catch (\Throwable $e) {
                Log::error('Failed to send student onboarding process email.', [
                    'student_id' => $student->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sent' => $sentTo !== [],
            'to' => $sentTo,
            'error' => $sentTo === [] ? 'send_failed' : null,
        ];
    }
}
