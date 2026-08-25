<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\StudentChapterCoverage;
use App\Models\StudentEnrollment;
use App\Support\SchoolStudyPlanReminderMailer;
use Illuminate\Support\Collection;

class StudyPlanReminderEmailService
{
    public function __construct(
        private StudentNotificationEmailService $emailService,
    ) {}

    /**
     * @param  list<int>|null  $onlyStudentIds  When set, only these students are included (mentor scope).
     * @return array{
     *     students: list<array<string, mixed>>,
     *     with_plan: list<array<string, mixed>>,
     *     without_plan: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         with_plan: int,
     *         without_plan: int,
     *         without_plan_with_email: int,
     *         without_plan_no_email: int
     *     }
     * }
     */
    public function classBreakdown(GradeLevel $gradeLevel, ?AcademicYear $year = null, ?array $onlyStudentIds = null): array
    {
        $year ??= AcademicYear::active();

        if (! $year) {
            return $this->emptyBreakdown();
        }

        if ($onlyStudentIds !== null && $onlyStudentIds === []) {
            return $this->emptyBreakdown();
        }

        $enrollments = StudentEnrollment::query()
            ->with(['student.user:id,email', 'board:id,name'])
            ->where('academic_year_id', $year->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->when($onlyStudentIds !== null, fn ($q) => $q->whereIn('student_id', $onlyStudentIds))
            ->whereHas('student')
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => mb_strtolower((string) $enrollment->student?->name))
            ->values();

        $coverageEnrollmentIds = StudentChapterCoverage::query()
            ->whereIn('student_enrollment_id', $enrollments->pluck('id'))
            ->distinct()
            ->pluck('student_enrollment_id')
            ->flip();

        $students = $enrollments->map(function (StudentEnrollment $enrollment) use ($coverageEnrollmentIds) {
            $student = $enrollment->student;
            $hasPlan = $coverageEnrollmentIds->has($enrollment->id);
            $recipients = $student
                ? $this->emailService->balanceReminderRecipients($student)
                : ['to' => [], 'cc' => []];
            $hasEmail = ($recipients['to'] ?? []) !== [] || ($recipients['cc'] ?? []) !== [];

            return [
                'id' => $enrollment->student_id,
                'name' => $student?->name,
                'enrollment_id' => $enrollment->id,
                'board_name' => $enrollment->board?->name,
                'has_study_plan' => $hasPlan,
                'has_email' => $hasEmail,
            ];
        })->values();

        $withPlan = $students->where('has_study_plan', true)->values();
        $withoutPlan = $students->where('has_study_plan', false)->values();

        return [
            'students' => $students->all(),
            'with_plan' => $withPlan->all(),
            'without_plan' => $withoutPlan->all(),
            'summary' => [
                'total' => $students->count(),
                'with_plan' => $withPlan->count(),
                'without_plan' => $withoutPlan->count(),
                'without_plan_with_email' => $withoutPlan->where('has_email', true)->count(),
                'without_plan_no_email' => $withoutPlan->where('has_email', false)->count(),
            ],
        ];
    }

    /**
     * Email students in the selected class who have not marked any study plan.
     *
     * @param  list<int>|null  $onlyStudentIds
     * @return array{sent: int, skipped: int, failed: int, already_planned: int}
     */
    public function sendToMissingInGrade(GradeLevel $gradeLevel, ?array $onlyStudentIds = null): array
    {
        $breakdown = $this->classBreakdown($gradeLevel, null, $onlyStudentIds);
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'already_planned' => $breakdown['summary']['with_plan']];

        /** @var Collection<int, array<string, mixed>> $withoutPlan */
        $withoutPlan = collect($breakdown['without_plan']);

        foreach ($withoutPlan as $row) {
            $enrollment = StudentEnrollment::query()
                ->with(['student.user:id,email', 'gradeLevel:id,name'])
                ->find($row['enrollment_id'] ?? null);

            if (! $enrollment?->student) {
                $counts['skipped']++;

                continue;
            }

            $result = $this->sendToEnrollment($enrollment);

            if ($result['sent']) {
                $counts['sent']++;
            } elseif ($result['error'] === 'no_email' || $result['error'] === 'has_plan') {
                $counts['skipped']++;
            } else {
                $counts['failed']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{sent: bool, to: list<string>, cc: list<string>, error: ?string}
     */
    public function sendToEnrollment(StudentEnrollment $enrollment): array
    {
        $student = $enrollment->student;

        if (! $student) {
            return ['sent' => false, 'to' => [], 'cc' => [], 'error' => 'no_student'];
        }

        $hasPlan = StudentChapterCoverage::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->exists();

        if ($hasPlan) {
            return ['sent' => false, 'to' => [], 'cc' => [], 'error' => 'has_plan'];
        }

        $recipients = $this->emailService->balanceReminderRecipients($student);

        $enrollment->loadMissing('gradeLevel:id,name');

        return SchoolStudyPlanReminderMailer::send($student, [
            'student_name' => $student->name,
            'grade_name' => $enrollment->gradeLevel?->name,
            'dashboard_url' => route('dashboard'),
        ], $recipients);
    }

    /**
     * @return array{
     *     students: list<array<string, mixed>>,
     *     with_plan: list<array<string, mixed>>,
     *     without_plan: list<array<string, mixed>>,
     *     summary: array{
     *         total: int,
     *         with_plan: int,
     *         without_plan: int,
     *         without_plan_with_email: int,
     *         without_plan_no_email: int
     *     }
     * }
     */
    private function emptyBreakdown(): array
    {
        return [
            'students' => [],
            'with_plan' => [],
            'without_plan' => [],
            'summary' => [
                'total' => 0,
                'with_plan' => 0,
                'without_plan' => 0,
                'without_plan_with_email' => 0,
                'without_plan_no_email' => 0,
            ],
        ];
    }
}
