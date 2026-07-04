<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\StudentEnrollment;
use Illuminate\Http\Request;

class DashboardService
{
    public function __construct(
        private ExamPlanService $examPlanService,
        private SetAttemptService $attemptService,
        private AdminGradeContext $gradeContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(?StudentEnrollment $enrollment): array
    {
        $examPlanMeta = ['upcoming' => [], 'past' => []];
        $syllabusChapters = [];
        $assignments = [];

        if ($enrollment) {
            $plans = $this->examPlanService->plansForEnrollment($enrollment);
            $split = $this->examPlanService->splitPlansByTiming($plans);
            $examPlanMeta = [
                'upcoming' => $split['upcoming']->values()->all(),
                'past' => $split['past']->values()->all(),
            ];
            $syllabusChapters = $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all();
            $assignments = $this->attemptService->dashboardForEnrollment($enrollment);
        }

        return [
            'assignments' => $assignments,
            'examPlans' => $examPlanMeta,
            'syllabusChapters' => $syllabusChapters,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
            'stats' => $this->studentStats($assignments, $examPlanMeta),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forAdmin(Request $request): array
    {
        $activeYear = AcademicYear::active();
        $grade = $this->gradeContext->resolve($request);

        if (! $activeYear) {
            return [
                'activeYear' => null,
                'selectedGrade' => $grade?->only(['id', 'name']),
                'stats' => [
                    'students_count' => 0,
                    'upcoming_exams_count' => 0,
                    'pending_sets_count' => 0,
                    'completed_sets_count' => 0,
                ],
                'students' => [],
            ];
        }

        $enrollments = StudentEnrollment::query()
            ->with([
                'student:id,name',
                'gradeLevel:id,name',
            ])
            ->where('academic_year_id', $activeYear->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->when($grade, fn ($query) => $query->where('grade_level_id', $grade->id))
            ->get()
            ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->student?->name ?? '')
            ->values();

        $students = $enrollments->map(function (StudentEnrollment $enrollment) {
            $plans = $this->examPlanService->plansForEnrollment($enrollment);
            $split = $this->examPlanService->splitPlansByTiming($plans);
            $assignments = collect($this->attemptService->dashboardForEnrollment($enrollment));

            $pending = $assignments->filter(
                fn (array $row) => ! in_array($row['status'], ['green', 'green-late'], true),
            )->values()->all();

            $completed = $assignments->filter(
                fn (array $row) => in_array($row['status'], ['green', 'green-late'], true),
            )->values()->all();

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name,
                'class_name' => $enrollment->gradeLevel?->name,
                'grade_level_id' => $enrollment->grade_level_id,
                'upcoming_exams' => $split['upcoming']->values()->all(),
                'past_exams' => $split['past']->values()->all(),
                'assignments_pending' => $pending,
                'assignments_completed' => $completed,
            ];
        })->values()->all();

        $upcomingExamsCount = collect($students)->sum(fn (array $row) => count($row['upcoming_exams']));
        $pendingSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_pending']));
        $completedSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_completed']));

        return [
            'activeYear' => $activeYear->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'stats' => [
                'students_count' => count($students),
                'upcoming_exams_count' => $upcomingExamsCount,
                'pending_sets_count' => $pendingSetsCount,
                'completed_sets_count' => $completedSetsCount,
            ],
            'students' => $students,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  array{upcoming: list<mixed>, past: list<mixed>}  $examPlans
     * @return array<string, int>
     */
    private function studentStats(array $assignments, array $examPlans): array
    {
        $assignmentsCollection = collect($assignments);

        return [
            'upcoming_exams' => count($examPlans['upcoming']),
            'past_exams' => count($examPlans['past']),
            'sets_todo' => $assignmentsCollection->filter(
                fn (array $row) => ! in_array($row['status'], ['green', 'green-late'], true),
            )->count(),
            'sets_done' => $assignmentsCollection->filter(
                fn (array $row) => in_array($row['status'], ['green', 'green-late'], true),
            )->count(),
        ];
    }
}
