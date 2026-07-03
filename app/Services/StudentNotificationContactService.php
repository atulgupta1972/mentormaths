<?php

namespace App\Services;

use App\Models\RegistrationRequest;
use App\Models\Student;

class StudentNotificationContactService
{
    /**
     * @return list<array{label: string, mobile: string, key: string}>
     */
    public function recipientsForStudent(Student $student): array
    {
        return $this->buildList(
            $student->student_mobile,
            $student->parent1_mobile,
            $student->parent2_mobile,
            $student->parent1_name,
            $student->parent2_name,
            (bool) $student->notify_student_mobile,
            (bool) $student->notify_parent1_mobile,
            (bool) $student->notify_parent2_mobile,
        );
    }

    /**
     * @return list<array{label: string, mobile: string, key: string}>
     */
    public function recipientsForRegistrationRequest(RegistrationRequest $registrationRequest): array
    {
        return $this->buildList(
            $registrationRequest->student_mobile,
            $registrationRequest->parent1_mobile,
            $registrationRequest->parent2_mobile,
            $registrationRequest->parent1_name,
            $registrationRequest->parent2_name,
            (bool) $registrationRequest->notify_student_mobile,
            (bool) $registrationRequest->notify_parent1_mobile,
            (bool) $registrationRequest->notify_parent2_mobile,
        );
    }

    /**
     * @return list<array{label: string, mobile: string, key: string}>
     */
    private function buildList(
        ?string $studentMobile,
        ?string $parent1Mobile,
        ?string $parent2Mobile,
        ?string $parent1Name,
        ?string $parent2Name,
        bool $notifyStudent,
        bool $notifyParent1,
        bool $notifyParent2,
    ): array {
        $recipients = [];

        if ($notifyStudent && filled($studentMobile)) {
            $recipients[] = [
                'key' => 'student',
                'label' => 'Student mobile',
                'mobile' => $studentMobile,
            ];
        }

        if ($notifyParent1 && filled($parent1Mobile)) {
            $recipients[] = [
                'key' => 'parent1',
                'label' => $parent1Name ? "Parent 1 ({$parent1Name})" : 'Parent 1 mobile',
                'mobile' => $parent1Mobile,
            ];
        }

        if ($notifyParent2 && filled($parent2Mobile)) {
            $recipients[] = [
                'key' => 'parent2',
                'label' => $parent2Name ? "Parent 2 ({$parent2Name})" : 'Parent 2 mobile',
                'mobile' => $parent2Mobile,
            ];
        }

        return $recipients;
    }
}
