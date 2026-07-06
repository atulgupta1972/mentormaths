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
        private QuestionResolutionService $resolutionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(?StudentEnrollment $enrollment): array
    {
        $examPlanMeta = ['upcoming' => [], 'past' => []];
        $syllabusChapters = [];
        $assignments = [];
        $resolutionItems = [];

        if ($enrollment) {
            $plans = $this->examPlanService->plansForEnrollment($enrollment);
            $split = $this->examPlanService->splitPlansByTiming($plans);
            $examPlanMeta = [
                'upcoming' => $split['upcoming']->values()->all(),
                'past' => $split['past']->values()->all(),
            ];
            $syllabusChapters = $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all();
            $assignments = $this->attemptService->dashboardForEnrollment($enrollment);
            $resolutionItems = $this->resolutionService->pendingForEnrollment($enrollment->id);
        }

        return [
            'assignments' => $assignments,
            'examPlans' => $examPlanMeta,
            'syllabusChapters' => $syllabusChapters,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
            'stats' => $this->studentStats($assignments, $examPlanMeta, count($resolutionItems)),
            'resolutionItems' => $resolutionItems,
            'resolutionCount' => count($resolutionItems),
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
                    'help_requests_count' => 0,
                ],
                'students' => [],
                'helpRequests' => [],
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

        $studentIds = $enrollments->pluck('student_id')->all();
        $helpRequests = $this->resolutionService
            ->pendingForStudentIds($studentIds, $activeYear->id)
            ->values()
            ->all();
        $helpByStudent = collect($helpRequests)->groupBy('student_id');

        $students = $enrollments->map(function (StudentEnrollment $enrollment) use ($helpByStudent) {
            $allPlans = $this->examPlanService->plansForEnrollment($enrollment, true);
            $split = $this->examPlanService->splitPlansByTiming($allPlans);
            $assignments = collect($this->attemptService->dashboardForEnrollment($enrollment));

            $pending = $assignments->filter(
                fn (array $row) => ! in_array($row['status'], ['green', 'green-late'], true),
            )->values()->all();

            $completed = $assignments->filter(
                fn (array $row) => in_array($row['status'], ['green', 'green-late'], true),
            )->values()->all();

            $studentHelp = $helpByStudent->get($enrollment->student_id, collect());

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name,
                'class_name' => $enrollment->gradeLevel?->name,
                'grade_level_id' => $enrollment->grade_level_id,
                'upcoming_exams' => $split['upcoming']->values()->all(),
                'past_exams' => $split['past']->values()->all(),
                'exam_plans' => $allPlans->values()->all(),
                'syllabus_chapters' => $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all(),
                'assignments_pending' => $pending,
                'assignments_completed' => $completed,
                'help_requests' => $studentHelp->values()->all(),
                'help_requests_count' => $studentHelp->count(),
            ];
        })->values()->all();

        $upcomingExamsCount = collect($students)->sum(fn (array $row) => count($row['upcoming_exams']));
        $pendingSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_pending']));
        $completedSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_completed']));
        $helpRequestsCount = count($helpRequests);

        return [
            'activeYear' => $activeYear->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'stats' => [
                'students_count' => count($students),
                'upcoming_exams_count' => $upcomingExamsCount,
                'pending_sets_count' => $pendingSetsCount,
                'completed_sets_count' => $completedSetsCount,
                'help_requests_count' => $helpRequestsCount,
            ],
            'students' => $students,
            'helpRequests' => $helpRequests,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  array{upcoming: list<mixed>, past: list<mixed>}  $examPlans
     * @return array<string, int>
     */
    private function studentStats(array $assignments, array $examPlans, int $resolutionCount = 0): array
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
            'resolution_count' => $resolutionCount,
        ];
    }
}
