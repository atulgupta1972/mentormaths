<?php

namespace App\Support;

use App\Mail\WrittenWorkCheckFailed;
use App\Mail\WrittenWorkGraded;
use App\Models\WrittenSubmission;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class WrittenSubmissionMailer
{
    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function sendGraded(WrittenSubmission $submission): array
    {
        $submission->loadMissing('assignment.enrollment.student');
        $student = $submission->assignment?->enrollment?->student;

        if (! $student) {
            return ['sent' => false, 'email' => null, 'error' => 'no_student'];
        }

        $studentEmail = AssignmentMailer::resolveStudentEmail($student);
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $studentEmail && ! $adminEmail) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        $summary = WrittenSubmissionSummary::forEmail($submission);

        try {
            $recipient = $studentEmail ?: $adminEmail;
            $pending = Mail::to($recipient);

            if ($studentEmail && $adminEmail && strcasecmp($adminEmail, $studentEmail) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send(new WrittenWorkGraded($student, $summary));

            return ['sent' => true, 'email' => $recipient, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send written work graded email.', [
                'submission_id' => $submission->id,
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $studentEmail ?: $adminEmail, 'error' => 'send_failed'];
        }
    }

    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function sendCheckFailed(WrittenSubmission $submission): array
    {
        $submission->loadMissing('assignment.enrollment.student');
        $student = $submission->assignment?->enrollment?->student;

        if (! $student) {
            return ['sent' => false, 'email' => null, 'error' => 'no_student'];
        }

        $studentEmail = AssignmentMailer::resolveStudentEmail($student);
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $studentEmail && ! $adminEmail) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        $summary = WrittenSubmissionSummary::forFailedEmail($submission);

        try {
            $recipient = $studentEmail ?: $adminEmail;
            $pending = Mail::to($recipient);

            if ($studentEmail && $adminEmail && strcasecmp($adminEmail, $studentEmail) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send(new WrittenWorkCheckFailed($student, $summary));

            return ['sent' => true, 'email' => $recipient, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send written work check failed email.', [
                'submission_id' => $submission->id,
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $studentEmail ?: $adminEmail, 'error' => 'send_failed'];
        }
    }
}
