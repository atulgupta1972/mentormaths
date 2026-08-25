<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;

class MentorClassHubService
{
    public function __construct(
        private StudentMentorService $mentorService,
        private ExamPlanService $examPlanService,
        private ClassHubProgressService $progress,
    ) {}

    /**
     * Class cards for mentor dashboard — coaching classes they teach + optional individual learners.
     *
     * @return list<array<string, mixed>>
     */
    public function classCards(User $mentor): array
    {
        $activeYear = AcademicYear::active();
        $teachers = CoachingClassTeacher::query()
            ->with('coachingClass:id,name,city,is_active')
            ->where('user_id', $mentor->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $cards = [];

        foreach ($teachers->groupBy('coaching_class_id') as $classId => $rows) {
            $class = $rows->first()?->coachingClass;
            if (! $class || ! $class->is_active) {
                continue;
            }

            $teacherIds = $rows->pluck('id')->all();
            $studentIds = Student::query()
                ->whereIn('coaching_class_teacher_id', $teacherIds)
                ->pluck('id')
                ->all();

            $activeCount = $this->activeEnrollmentCount($studentIds, $activeYear?->id);

            $cards[] = [
                'id' => (int) $classId,
                'type' => 'coaching',
                'name' => $class->name,
                'city' => $class->city,
                'teacher_names' => $rows->pluck('name')->unique()->values()->all(),
                'students_count' => $activeCount,
            ];
        }

        $allMentorStudentIds = $this->mentorService->studentIdsForUser($mentor);
        $coachingStudentIds = $teachers === null || $teachers->isEmpty()
            ? []
            : Student::query()
                ->whereIn('coaching_class_teacher_id', $teachers->pluck('id')->all())
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        $individualIds = array_values(array_diff($allMentorStudentIds, $coachingStudentIds));
        $individualCount = $this->activeEnrollmentCount($individualIds, $activeYear?->id);

        if ($individualCount > 0 || ($cards === [] && $allMentorStudentIds !== [])) {
            $cards[] = [
                'id' => 0,
                'type' => 'individual',
                'name' => 'Individual learners',
                'city' => null,
                'teacher_names' => [$mentor->name],
                'students_count' => $individualCount,
            ];
        }

        return $cards;
    }

    /**
     * @return array{
     *     coachingClass: ?array<string, mixed>,
     *     examPlanRows: list<array<string, mixed>>,
     *     examPlanStats: array<string, int>,
     *     examFilter: string,
     *     activeYear: ?array<string, mixed>,
     * }
     */
    public function classDetail(User $mentor, int $coachingClassId, string $examFilter = 'upcoming'): array
    {
        if (! in_array($examFilter, ['upcoming', 'past', 'all'], true)) {
            $examFilter = 'upcoming';
        }

        $activeYear = AcademicYear::active();
        $studentIds = $this->studentIdsForClassCard($mentor, $coachingClassId);

        $coachingClass = null;
        if ($coachingClassId > 0) {
            $class = CoachingClass::query()->findOrFail($coachingClassId);
            $this->assertMentorTeachesClass($mentor, $class);
            $coachingClass = [
                'id' => $class->id,
                'name' => $class->name,
                'city' => $class->city,
                'type' => 'coaching',
            ];
        } else {
            $coachingClass = [
                'id' => 0,
                'name' => 'Individual learners',
                'city' => null,
                'type' => 'individual',
            ];
        }

        $examPlanRows = [];
        $examPlanStats = ['with_upcoming' => 0, 'without_plan' => 0, 'without_upcoming' => 0];

        if ($activeYear && $studentIds !== []) {
            $enrollments = StudentEnrollment::query()
                ->with(['student:id,name', 'academicYear', 'gradeLevel:id,name', 'board:id,name'])
                ->where('academic_year_id', $activeYear->id)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->whereIn('student_id', $studentIds)
                ->orderBy('id')
                ->get();

            $examPlanRows = $this->examPlanService->classHubRows($enrollments, $examFilter, true);
            $examPlanRows = $this->progress->attach($enrollments, $examPlanRows);
            $examPlanStats = [
                'with_upcoming' => collect($examPlanRows)->where('has_upcoming', true)->count(),
                'without_plan' => collect($examPlanRows)->where('has_plan', false)->count(),
                'without_upcoming' => collect($examPlanRows)->where('has_upcoming', false)->count(),
            ];
        }

        return [
            'coachingClass' => $coachingClass,
            'examPlanRows' => $examPlanRows,
            'examPlanStats' => $examPlanStats,
            'examFilter' => $examFilter,
            'activeYear' => $activeYear?->only(['id', 'name']),
            'students_count' => count($examPlanRows),
        ];
    }

    /**
     * @return list<int>
     */
    private function studentIdsForClassCard(User $mentor, int $coachingClassId): array
    {
        if ($coachingClassId > 0) {
            $teacherIds = CoachingClassTeacher::query()
                ->where('coaching_class_id', $coachingClassId)
                ->where('user_id', $mentor->id)
                ->pluck('id')
                ->all();

            if ($teacherIds === []) {
                abort(403, 'You are not assigned to this class.');
            }

            return Student::query()
                ->whereIn('coaching_class_teacher_id', $teacherIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $all = $this->mentorService->studentIdsForUser($mentor);
        $teacherIds = CoachingClassTeacher::query()
            ->where('user_id', $mentor->id)
            ->pluck('id')
            ->all();

        $coachingStudentIds = $teacherIds === []
            ? []
            : Student::query()
                ->whereIn('coaching_class_teacher_id', $teacherIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

        return array_values(array_diff($all, $coachingStudentIds));
    }

    private function assertMentorTeachesClass(User $mentor, CoachingClass $class): void
    {
        $teaches = CoachingClassTeacher::query()
            ->where('coaching_class_id', $class->id)
            ->where('user_id', $mentor->id)
            ->exists();

        if (! $teaches) {
            abort(403, 'You are not assigned to this class.');
        }
    }

    /**
     * @param  list<int>  $studentIds
     */
    private function activeEnrollmentCount(array $studentIds, ?int $yearId): int
    {
        if ($studentIds === [] || ! $yearId) {
            return 0;
        }

        return StudentEnrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->whereIn('student_id', $studentIds)
            ->count();
    }
}
