<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\ContentUploadTask;
use App\Models\ExamPlan;
use App\Models\QuestionResolutionItem;
use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function forStudent(?StudentEnrollment $enrollment, ?int $gradeLevelId = null, ?int $boardId = null): array
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
            return $this->emptyAdminPayload($request);
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
        $enrollmentIds = $enrollments->pluck('id')->all();

        $helpCounts = collect();
        try {
            if ($studentIds !== []) {
                $helpCounts = QuestionResolutionItem::query()
                    ->select('student_enrollments.student_id')
                    ->selectRaw('count(*) as c')
                    ->join('student_enrollments', 'student_enrollments.id', '=', 'question_resolution_items.student_enrollment_id')
                    ->where('question_resolution_items.status', QuestionResolutionItem::STATUS_PENDING)
                    ->whereIn('student_enrollments.student_id', $studentIds)
                    ->where('student_enrollments.academic_year_id', $activeYear->id)
                    ->groupBy('student_enrollments.student_id')
                    ->pluck('c', 'student_id');
            }
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to count help requests.', ['message' => $e->getMessage()]);
        }

        $examCounts = collect();
        $assignmentCounts = collect();
        try {
            if ($enrollmentIds !== []) {
                $examCounts = ExamPlan::query()
                    ->selectRaw('student_enrollment_id, count(*) as c')
                    ->whereIn('student_enrollment_id', $enrollmentIds)
                    ->where('status', ExamPlan::STATUS_PLANNED)
                    ->whereDate('exam_date', '>=', now()->toDateString())
                    ->groupBy('student_enrollment_id')
                    ->pluck('c', 'student_enrollment_id');

                $assignmentCounts = SetAssignment::query()
                    ->selectRaw('student_enrollment_id, status, count(*) as c')
                    ->whereIn('student_enrollment_id', $enrollmentIds)
                    ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
                    ->groupBy('student_enrollment_id', 'status')
                    ->get()
                    ->groupBy('student_enrollment_id');
            }
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to count exams/assignments.', ['message' => $e->getMessage()]);
        }

        $students = $enrollments->map(function (StudentEnrollment $enrollment) use (
            $helpCounts,
            $examCounts,
            $assignmentCounts,
        ) {
            $byStatus = $assignmentCounts->get($enrollment->id)
                ?? $assignmentCounts->get((string) $enrollment->id)
                ?? collect();
            $completed = (int) $byStatus->firstWhere('status', SetAssignment::STATUS_COMPLETED)?->c;
            $total = (int) $byStatus->sum('c');
            $helpCount = (int) ($helpCounts->get($enrollment->student_id) ?? $helpCounts->get((string) $enrollment->student_id) ?? 0);
            $examCount = (int) ($examCounts->get($enrollment->id) ?? $examCounts->get((string) $enrollment->id) ?? 0);

            return [
                'student_id' => $enrollment->student_id,
                'student_name' => $enrollment->student?->name,
                'class_name' => $enrollment->gradeLevel?->name,
                'grade_level_id' => $enrollment->grade_level_id,
                'upcoming_exams' => [],
                'upcoming_exams_count' => $examCount,
                'past_exams' => [],
                'exam_plans' => [],
                'syllabus_chapters' => [],
                'assignments_pending' => [],
                'assignments_pending_count' => max(0, $total - $completed),
                'assignments_under_review' => [],
                'assignments_under_review_count' => 0,
                'assignments_completed' => [],
                'assignments_completed_count' => $completed,
                'help_requests' => [],
                'help_requests_count' => $helpCount,
            ];
        })->values()->all();

        $helpRequestsCount = (int) $helpCounts->sum();

        $contentPublishQueue = [];
        try {
            $contentPublishQueue = ContentUploadTask::query()
                ->with([
                    'assignee:id,name',
                    'textbookChapter:id,chapter_number,title,textbook_id',
                    'textbookChapter.textbook:id,name,grade_level_id',
                    'textbookChapter.textbook.gradeLevel:id,name',
                ])
                ->where('status', ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH)
                ->latest('submitted_at')
                ->limit(20)
                ->get()
                ->map(fn (ContentUploadTask $task) => [
                    'id' => $task->id,
                    'assignee_name' => $task->assignee?->name,
                    'chapter_number' => $task->textbookChapter?->chapter_number,
                    'chapter_title' => $task->textbookChapter?->title,
                    'grade_name' => $task->textbookChapter?->textbook?->gradeLevel?->name,
                    'textbook_name' => $task->textbookChapter?->textbook?->name,
                    'submitted_at' => $task->submitted_at?->toIso8601String(),
                    'agreed_amount_inr' => $task->agreed_amount_inr,
                ])
                ->all();
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load the content publish queue.', ['message' => $e->getMessage()]);
        }

        return [
            'activeYear' => $activeYear->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'stats' => [
                'students_count' => count($students),
                'upcoming_exams_count' => collect($students)->sum(fn (array $row) => (int) ($row['upcoming_exams_count'] ?? 0)),
                'pending_sets_count' => collect($students)->sum(fn (array $row) => (int) ($row['assignments_pending_count'] ?? 0)),
                'under_review_sets_count' => collect($students)->sum(fn (array $row) => (int) ($row['assignments_under_review_count'] ?? 0)),
                'completed_sets_count' => collect($students)->sum(fn (array $row) => (int) ($row['assignments_completed_count'] ?? 0)),
                'help_requests_count' => $helpRequestsCount,
                'content_publish_queue_count' => count($contentPublishQueue),
            ],
            'students' => $students,
            'helpRequests' => [],
            'contentPublishQueue' => $contentPublishQueue,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyAdminPayload(Request $request): array
    {
        $grade = $this->gradeContext->resolve($request);

        return [
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'stats' => [
                'students_count' => 0,
                'upcoming_exams_count' => 0,
                'pending_sets_count' => 0,
                'under_review_sets_count' => 0,
                'completed_sets_count' => 0,
                'help_requests_count' => 0,
                'content_publish_queue_count' => 0,
            ],
            'students' => [],
            'helpRequests' => [],
            'contentPublishQueue' => [],
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
        ];
    }

    /**
     * Full exam-plan payload used when an admin expands one student.
     *
     * @return array<string, mixed>
     */
    public function adminStudentDetail(StudentEnrollment $enrollment): array
    {
        $helpByStudent = collect($this->resolutionService
            ->pendingForStudentIds([$enrollment->student_id], $enrollment->academic_year_id)
            ->values()
            ->all())->groupBy('student_id');

        return $this->serializeAdminStudentRow($enrollment, $helpByStudent);
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $helpByStudent
     * @return array<string, mixed>
     */
    private function emptyAdminStudentRow(StudentEnrollment $enrollment, $helpByStudent): array
    {
        $studentHelp = $helpByStudent->get($enrollment->student_id, collect());

        return [
            'student_id' => $enrollment->student_id,
            'student_name' => $enrollment->student?->name,
            'class_name' => $enrollment->gradeLevel?->name,
            'grade_level_id' => $enrollment->grade_level_id,
            'upcoming_exams' => [],
            'past_exams' => [],
            'exam_plans' => [],
            'syllabus_chapters' => [],
            'assignments_pending' => [],
            'assignments_under_review' => [],
            'assignments_completed' => [],
            'help_requests' => $studentHelp->values()->all(),
            'help_requests_count' => $studentHelp->count(),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $helpByStudent
     * @param  list<array<string, mixed>>  $assignments
     * @param  \Illuminate\Support\Collection<int, ExamPlan>  $examPlans
     * @return array<string, mixed>
     */
    private function serializeAdminStudentListRow(
        StudentEnrollment $enrollment,
        $helpByStudent,
        array $assignments,
        $examPlans,
    ): array {
        $formattedPlans = collect($examPlans)
            ->map(fn (ExamPlan $plan) => $this->examPlanService->formatPlan($plan, false, false));
        $split = $this->examPlanService->splitPlansByTiming($formattedPlans);

        return $this->adminStudentPayload(
            $enrollment,
            $helpByStudent,
            collect($assignments),
            $split,
            $formattedPlans,
            [],
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $helpByStudent
     * @return array<string, mixed>
     */
    private function serializeAdminStudentRow(StudentEnrollment $enrollment, $helpByStudent): array
    {
        $allPlans = $this->examPlanService->plansForEnrollment($enrollment, true);
        $split = $this->examPlanService->splitPlansByTiming($allPlans);
        $assignments = collect($this->attemptService->dashboardForEnrollment($enrollment));

        return $this->adminStudentPayload(
            $enrollment,
            $helpByStudent,
            $assignments,
            $split,
            $allPlans,
            $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all(),
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $helpByStudent
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $assignments
     * @param  array{upcoming: \Illuminate\Support\Collection, past: \Illuminate\Support\Collection}  $split
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $allPlans
     * @param  list<array<string, mixed>>  $syllabusChapters
     * @return array<string, mixed>
     */
    private function adminStudentPayload(
        StudentEnrollment $enrollment,
        $helpByStudent,
        $assignments,
        array $split,
        $allPlans,
        array $syllabusChapters,
    ): array {
        $completed = $assignments->filter(
            fn (array $row) => in_array($row['status'], ['green', 'green-late'], true),
        )->sortBy(fn (array $row) => $row['submitted_at'] ?? '9999-12-31 23:59:59')->values()->all();

        $underReview = $assignments->filter(
            fn (array $row) => ($row['status'] ?? null) === 'checking',
        )->sortBy(fn (array $row) => $row['submitted_at'] ?? '9999-12-31 23:59:59')->values()->all();

        $pending = $assignments->filter(
            fn (array $row) => ! in_array($row['status'], ['green', 'green-late', 'checking'], true),
        )->sortBy(fn (array $row) => $row['target_date'] ?? '9999-12-31')->values()->all();

        $studentHelp = $helpByStudent->get($enrollment->student_id, collect());

        return [
            'student_id' => $enrollment->student_id,
            'student_name' => $enrollment->student?->name,
            'class_name' => $enrollment->gradeLevel?->name,
            'grade_level_id' => $enrollment->grade_level_id,
            'upcoming_exams' => $split['upcoming']->values()->all(),
            'past_exams' => $split['past']->values()->all(),
            'exam_plans' => $allPlans->values()->all(),
            'syllabus_chapters' => $syllabusChapters,
            'assignments_pending' => $pending,
            'assignments_under_review' => $underReview,
            'assignments_completed' => $completed,
            'help_requests' => $studentHelp->values()->all(),
            'help_requests_count' => $studentHelp->count(),
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
                fn (array $row) => ! in_array($row['status'], ['green', 'green-late', 'checking'], true),
            )->count(),
            'sets_under_review' => $assignmentsCollection->filter(
                fn (array $row) => ($row['status'] ?? null) === 'checking',
            )->count(),
            'sets_done' => $assignmentsCollection->filter(
                fn (array $row) => in_array($row['status'], ['green', 'green-late'], true),
            )->count(),
            'resolution_count' => $resolutionCount,
        ];
    }
}
