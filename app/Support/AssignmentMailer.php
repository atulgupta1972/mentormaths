<?php

namespace App\Support;

use App\Mail\AssignmentAssigned;
use App\Mail\AssignmentCompleted;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\AttemptResultSummary;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AssignmentMailer
{
    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function sendCompleted(SetAttempt $attempt): array
    {
        $attempt->loadMissing('assignment.enrollment.student');
        $student = $attempt->assignment->enrollment->student;

        if (! $student) {
            return ['sent' => false, 'email' => null, 'error' => 'no_student'];
        }

        $studentEmail = self::resolveStudentEmail($student);
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $studentEmail && ! $adminEmail) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        $relations = [
            'answers.question.topic.chapter',
            'guidedQuestions.question.topic.chapter',
            'assignment.practiceSet.topic.chapter',
            'assignment.practiceSet.chapter',
            'assignment.practiceSet.questions.topic.chapter',
            'assignment.attempts',
        ];

        $summary = AttemptResultSummary::forAdmin($attempt->fresh($relations));

        try {
            $recipient = $studentEmail ?: $adminEmail;
            $pending = Mail::to($recipient);

            if ($studentEmail && $adminEmail && strcasecmp($adminEmail, $studentEmail) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send(new AssignmentCompleted($student, $summary));

            return ['sent' => true, 'email' => $recipient, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send completion email.', [
                'attempt_id' => $attempt->id,
                'student_id' => $student->id,
                'student_email' => $studentEmail,
                'admin_email' => $adminEmail,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $studentEmail ?: $adminEmail, 'error' => 'send_failed'];
        }
    }

    public static function sendAssigned(
        Student $student,
        Worksheet $worksheet,
        string $dueDate,
        ?string $notes = null,
    ): array {
        return self::sendAssignedMany($student, [$worksheet], $dueDate, $notes);
    }

    /**
     * @param  list<Worksheet>  $worksheets
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    public static function sendAssignedMany(
        Student $student,
        array $worksheets,
        string $dueDate,
        ?string $notes = null,
    ): array {
        if ($worksheets === []) {
            return ['sent' => false, 'email' => null, 'error' => 'no_worksheets'];
        }

        $email = self::resolveStudentEmail($student);

        if (! $email) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        try {
            $pending = Mail::to($email);

            if ($adminEmail && strcasecmp($adminEmail, $email) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send(new AssignmentAssigned($student, $worksheets, $dueDate, $notes));

            $viaLog = config('mail.default') === 'log';

            return ['sent' => true, 'email' => $email, 'error' => null, 'via_log' => $viaLog];
        } catch (\Throwable $e) {
            Log::error('Failed to send assignment email.', [
                'student_id' => $student->id,
                'email' => $email,
                'worksheet_ids' => collect($worksheets)->pluck('id')->all(),
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $email, 'error' => 'send_failed'];
        }
    }

    /**
     * @param  list<Student>  $students
     * @return array{sent: int, skipped: int, failed: int}
     */
    public static function sendBulkAssigned(
        array $students,
        Worksheet $worksheet,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($students as $student) {
            $result = self::sendAssigned($student, $worksheet, $dueDate, $notes);

            if ($result['sent']) {
                $counts['sent']++;
                if (! empty($result['via_log'])) {
                    $counts['via_log'] = true;
                }
            } elseif ($result['error'] === 'no_email') {
                $counts['skipped']++;
            } else {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * @param  array<int, list<Worksheet>>  $worksheetsByStudentId
     * @return array{sent: int, skipped: int, failed: int}
     */
    public static function sendClassMultiAssigned(
        array $worksheetsByStudentId,
        string $dueDate,
        ?string $notes = null,
    ): array {
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($worksheetsByStudentId as $studentId => $worksheets) {
            if ($worksheets === []) {
                continue;
            }

            $student = Student::query()->find($studentId);

            if (! $student) {
                $counts['skipped']++;

                continue;
            }

            $result = self::sendAssignedMany($student, $worksheets, $dueDate, $notes);

            if ($result['sent']) {
                $counts['sent']++;
                if (! empty($result['via_log'])) {
                    $counts['via_log'] = true;
                }
            } elseif ($result['error'] === 'no_email') {
                $counts['skipped']++;
            } else {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    public static function resolveStudentEmail(Student $student): ?string
    {
        if (self::isDeliverableEmail($student->email)) {
            return $student->email;
        }

        $userId = $student->getAttribute('user_id');

        if ($userId === null && $student->exists) {
            $userId = Student::query()->whereKey($student->id)->value('user_id');
        }

        if ($userId) {
            $student->loadMissing('user');

            if ($student->user && self::isDeliverableEmail($student->user->email)) {
                return $student->user->email;
            }

            $loginEmail = User::query()->whereKey($userId)->value('email');

            if (self::isDeliverableEmail($loginEmail)) {
                return $loginEmail;
            }
        }

        return null;
    }

    public static function isDeliverableEmail(?string $email): bool
    {
        if (! filled($email) || ! str_contains($email, '@')) {
            return false;
        }

        return ! str_ends_with(strtolower($email), '@mathsfoundation.local');
    }

    /**
     * @param  array{sent: bool, email: ?string, error: ?string}  $result
     */
    public static function flashSuffixForSingle(array $result, string $studentName): ?string
    {
        if ($result['sent']) {
            $to = $result['email'] ?? 'recipient';
            $suffix = " Email sent to {$to}";

            if (! empty($result['via_log'])) {
                return $suffix.' (log mailer only — not delivered; set MAIL_MAILER=smtp in .env).';
            }

            return $suffix.' (admin CC\'d).';
        }

        if ($result['error'] === 'no_email') {
            return " No email sent for {$studentName} — add a contact email on their profile or ensure their login account is linked.";
        }

        return " Email could not be sent for {$studentName}.";
    }

    /**
     * @param  array{sent: int, skipped: int, failed: int}  $counts
     */
    public static function flashSuffixForBulk(array $counts): ?string
    {
        if ($counts['sent'] === 0 && $counts['skipped'] === 0 && $counts['failed'] === 0) {
            return null;
        }

        $parts = [];

        if ($counts['sent'] > 0) {
            $parts[] = "{$counts['sent']} email".($counts['sent'] === 1 ? '' : 's').' sent';
        }

        if ($counts['skipped'] > 0) {
            $parts[] = "{$counts['skipped']} skipped (no deliverable email on file)";
        }

        if ($counts['failed'] > 0) {
            $parts[] = "{$counts['failed']} failed to send";
        }

        return ' '.implode('; ', $parts).'.';
    }
}
