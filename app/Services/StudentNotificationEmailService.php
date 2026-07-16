<?php

namespace App\Services;

use App\Models\Student;
use App\Support\AssignmentMailer;

class StudentNotificationEmailService
{
    /**
     * @return list<array{key: string, label: string, email: string}>
     */
    public function recipientsForStudent(Student $student): array
    {
        $student->loadMissing('user:id,email');

        $recipients = [];

        if ($student->notify_contact_email && AssignmentMailer::isDeliverableEmail($student->email)) {
            $recipients[] = [
                'key' => 'contact',
                'label' => 'Contact email',
                'email' => $student->email,
            ];
        }

        $loginEmail = $student->user?->email;

        if (
            $student->notify_login_email
            && AssignmentMailer::isDeliverableEmail($loginEmail)
            && ! $this->hasEmail($recipients, $loginEmail)
        ) {
            $recipients[] = [
                'key' => 'login',
                'label' => 'Student login email',
                'email' => $loginEmail,
            ];
        }

        if (
            $student->notify_parent1_email
            && AssignmentMailer::isDeliverableEmail($student->parent1_email)
        ) {
            $recipients[] = [
                'key' => 'parent1',
                'label' => $student->parent1_name
                    ? "Parent 1 ({$student->parent1_name})"
                    : 'Parent 1 email',
                'email' => $student->parent1_email,
            ];
        }

        if (
            $student->notify_parent2_email
            && AssignmentMailer::isDeliverableEmail($student->parent2_email)
        ) {
            $recipients[] = [
                'key' => 'parent2',
                'label' => $student->parent2_name
                    ? "Parent 2 ({$student->parent2_name})"
                    : 'Parent 2 email',
                'email' => $student->parent2_email,
            ];
        }

        return $recipients;
    }

    /**
     * @return list<string>
     */
    public function emailAddressesForStudent(Student $student): array
    {
        return array_values(array_unique(array_map(
            fn (array $row) => $row['email'],
            $this->recipientsForStudent($student),
        )));
    }

    /**
     * @param  list<array{key: string, label: string, email: string}>  $recipients
     */
    private function hasEmail(array $recipients, string $email): bool
    {
        foreach ($recipients as $recipient) {
            if (strcasecmp($recipient['email'], $email) === 0) {
                return true;
            }
        }

        return false;
    }
}
