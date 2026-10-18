<?php

namespace App\Services;

use App\Models\ExamPlan;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Support\Collection;

/**
 * Term 1 / Half yearly marks must be entered before drills and tests.
 */
class FirstTermMarksGate
{
    public function isRequired(): bool
    {
        return (bool) config('exam_gates.first_term_marks_required', true);
    }

    public function examType(): string
    {
        return (string) config('exam_gates.first_term_exam_type', ExamPlan::TYPE_HALF_YEARLY);
    }

    public function gatePassed(?Student $student): bool
    {
        if (! $this->isRequired()) {
            return true;
        }

        $enrollment = $student?->currentEnrollment();
        if (! $enrollment) {
            return true;
        }

        return $this->enrollmentPassed($enrollment);
    }

    public function enrollmentPassed(StudentEnrollment $enrollment): bool
    {
        $plans = $this->pastFirstTermPlans($enrollment);

        if ($plans->isEmpty()) {
            return false;
        }

        return $plans->every(fn (ExamPlan $plan) => $plan->hasMarks());
    }

    /**
     * @return Collection<int, ExamPlan>
     */
    public function pastFirstTermPlans(StudentEnrollment $enrollment): Collection
    {
        return ExamPlan::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('exam_type', $this->examType())
            ->whereDate('exam_date', '<=', now()->toDateString())
            ->orderBy('exam_date')
            ->get();
    }

    /**
     * @return Collection<int, ExamPlan>
     */
    public function missingMarksPlans(StudentEnrollment $enrollment): Collection
    {
        return $this->pastFirstTermPlans($enrollment)
            ->filter(fn (ExamPlan $plan) => ! $plan->hasMarks())
            ->values();
    }

    public function blockingMessage(?Student $student = null): string
    {
        $enrollment = $student?->currentEnrollment();
        if ($enrollment && $this->pastFirstTermPlans($enrollment)->isNotEmpty()) {
            return 'Term 1 (Half yearly) is over — enter your school marks on My Exams before starting drills or tests.';
        }

        return 'Term 1 (Half yearly) is over — add your Half yearly exam and enter school marks on My Exams before starting drills or tests.';
    }

    /**
     * @return array{
     *     required: bool,
     *     satisfied: bool,
     *     exam_type: string,
     *     exam_type_label: string,
     *     message: string|null,
     *     missing_plan_ids: list<int>
     * }
     */
    public function statusForEnrollment(?StudentEnrollment $enrollment): array
    {
        $required = $this->isRequired();
        $type = $this->examType();
        $label = (new ExamPlan(['exam_type' => $type]))->typeLabel();

        if (! $required || ! $enrollment) {
            return [
                'required' => $required,
                'satisfied' => true,
                'exam_type' => $type,
                'exam_type_label' => $label,
                'message' => null,
                'missing_plan_ids' => [],
            ];
        }

        $satisfied = $this->enrollmentPassed($enrollment);
        $missing = $this->missingMarksPlans($enrollment);
        $enrollment->loadMissing('student');

        return [
            'required' => true,
            'satisfied' => $satisfied,
            'exam_type' => $type,
            'exam_type_label' => $label,
            'message' => $satisfied ? null : $this->blockingMessage($enrollment->student),
            'missing_plan_ids' => $missing->pluck('id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
