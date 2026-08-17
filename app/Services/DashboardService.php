<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\ContentUploadTask;
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

        try {
            $helpRequests = $this->resolutionService
                ->pendingForStudentIds($studentIds, $activeYear->id)
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load help requests.', ['message' => $e->getMessage()]);
            $helpRequests = [];
        }

        $helpByStudent = collect($helpRequests)->groupBy('student_id');

        $students = $enrollments->map(function (StudentEnrollment $enrollment) use ($helpByStudent) {
            try {
                return $this->serializeAdminStudentRow($enrollment, $helpByStudent);
            } catch (Throwable $e) {
                Log::error('Admin dashboard failed to load a student row.', [
                    'student_id' => $enrollment->student_id,
                    'enrollment_id' => $enrollment->id,
                    'message' => $e->getMessage(),
                ]);

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
        })->values()->all();

        $upcomingExamsCount = collect($students)->sum(fn (array $row) => count($row['upcoming_exams']));
        $pendingSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_pending']));
        $underReviewSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_under_review']));
        $completedSetsCount = collect($students)->sum(fn (array $row) => count($row['assignments_completed']));
        $helpRequestsCount = count($helpRequests);

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
            $contentPublishQueue = [];
        }

        return [
            'activeYear' => $activeYear->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'stats' => [
                'students_count' => count($students),
                'upcoming_exams_count' => $upcomingExamsCount,
                'pending_sets_count' => $pendingSetsCount,
                'under_review_sets_count' => $underReviewSetsCount,
                'completed_sets_count' => $completedSetsCount,
                'help_requests_count' => $helpRequestsCount,
                'content_publish_queue_count' => count($contentPublishQueue),
            ],
            'students' => $students,
            'helpRequests' => $helpRequests,
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
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, array<string, mixed>>>  $helpByStudent
     * @return array<string, mixed>
     */
    private function serializeAdminStudentRow(StudentEnrollment $enrollment, $helpByStudent): array
    {
        $allPlans = $this->examPlanService->plansForEnrollment($enrollment, true);
        $split = $this->examPlanService->splitPlansByTiming($allPlans);
        $assignments = collect($this->attemptService->dashboardForEnrollment($enrollment));

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
            'syllabus_chapters' => $this->examPlanService->chapterOptionsForEnrollment($enrollment)->values()->all(),
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
