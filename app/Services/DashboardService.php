<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\ContentUploadTask;
use App\Models\ExamPlan;
use App\Models\QuestionResolutionItem;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Support\AttemptIntegrity;
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
        private QuestionIssueReportService $issueReports,
        private ContentVerificationService $verificationService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forStudent(
        ?StudentEnrollment $enrollment,
        ?int $gradeLevelId = null,
        ?int $boardId = null,
        bool $includeAssignmentList = true,
    ): array {
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

        $payload = [
            'resumeItems' => $this->resumeItemsFromAssignments($assignments),
            'examPlans' => $examPlanMeta,
            'syllabusChapters' => $syllabusChapters,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
            'stats' => $this->studentStats($assignments, $examPlanMeta, count($resolutionItems)),
            'resolutionItems' => $resolutionItems,
            'resolutionCount' => count($resolutionItems),
        ];

        if ($includeAssignmentList) {
            $payload['assignments'] = $assignments;
        }

        return $payload;
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
        $helpRequests = [];
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

                $helpRequests = $this->resolutionService
                    ->pendingForStudentIds($studentIds, $activeYear->id)
                    ->values()
                    ->all();
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

        $questionIssueCounts = collect();
        $questionIssueReports = [];
        $questionIssueReportsSentToUploader = [];
        try {
            if ($studentIds !== [] && \Illuminate\Support\Facades\Schema::hasTable('question_issue_reports')) {
                $questionIssueCounts = $this->issueReports->pendingCountForStudentIds($studentIds);
                $questionIssueReports = $this->issueReports->pendingForAdmin($studentIds);
                $questionIssueReportsSentToUploader = $this->issueReports->sentToUploaderForAdmin($studentIds);
            }
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load question issue reports.', ['message' => $e->getMessage()]);
        }

        $students = $enrollments->map(function (StudentEnrollment $enrollment) use (
            $helpCounts,
            $questionIssueCounts,
            $examCounts,
            $assignmentCounts,
        ) {
            $byStatus = $assignmentCounts->get($enrollment->id)
                ?? $assignmentCounts->get((string) $enrollment->id)
                ?? collect();
            $completed = (int) $byStatus->firstWhere('status', SetAssignment::STATUS_COMPLETED)?->c;
            $total = (int) $byStatus->sum('c');
            $helpCount = (int) ($helpCounts->get($enrollment->student_id) ?? $helpCounts->get((string) $enrollment->student_id) ?? 0);
            $issueCount = (int) ($questionIssueCounts->get($enrollment->student_id) ?? $questionIssueCounts->get((string) $enrollment->student_id) ?? 0);
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
                'question_issue_reports_count' => $issueCount,
            ];
        })->values()->all();

        $helpRequestsCount = count($helpRequests) > 0
            ? count($helpRequests)
            : (int) $helpCounts->sum();

        $lockedAttempts = [];
        try {
            $lockedAttempts = $this->lockedAttemptsForEnrollments($enrollmentIds);
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load locked attempts.', ['message' => $e->getMessage()]);
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
                'question_issue_reports_count' => count($questionIssueReports) + count($questionIssueReportsSentToUploader),
                'question_issue_reports_pending_count' => count($questionIssueReports),
                'question_issue_reports_sent_count' => count($questionIssueReportsSentToUploader),
                'locked_attempts_count' => count($lockedAttempts),
                'content_publish_queue_count' => 0,
                'content_recheck_queue_count' => 0,
                'gemini_pending_count' => 0,
                'gemini_done_count' => 0,
            ],
            'students' => $students,
            'helpRequests' => $helpRequests,
            'questionIssueReports' => $questionIssueReports,
            'questionIssueReportsSentToUploader' => $questionIssueReportsSentToUploader,
            'lockedAttempts' => $lockedAttempts,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contentPublishQueueForAdmin(Request $request): array
    {
        try {
            $grade = $this->gradeContext->resolve($request);

            return $this->contentTaskQueryForAdmin($grade)
                ->where('status', ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH)
                ->latest('submitted_at')
                ->limit(20)
                ->get()
                ->map(fn (ContentUploadTask $task) => $this->serializeDashboardContentTask($task))
                ->all();
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load publish queue.', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contentRecheckQueueForAdmin(Request $request): array
    {
        try {
            $grade = $this->gradeContext->resolve($request);

            $contentRecheckTasks = $this->contentTaskQueryForAdmin($grade)
                ->where('status', ContentUploadTask::STATUS_PUBLISHED)
                ->latest('published_at')
                ->limit(30)
                ->get();

            $recheckProgress = $request->user()
                ? $this->verificationService->progressForTasks($contentRecheckTasks, $request->user())
                : [];

            return $contentRecheckTasks
                ->map(fn (ContentUploadTask $task) => $this->serializeDashboardContentTask(
                    $task,
                    $recheckProgress[(int) $task->id] ?? null,
                ))
                ->all();
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load recheck queue.', ['message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ContentUploadTask>
     */
    private function contentTaskQueryForAdmin(?\App\Models\GradeLevel $grade)
    {
        return ContentUploadTask::query()
            ->with([
                'assignee:id,name',
                'textbookChapter:id,chapter_number,title,textbook_id',
                'textbookChapter.textbook:id,name,grade_level_id',
                'textbookChapter.textbook.gradeLevel:id,name',
            ])
            ->when(
                $grade,
                fn ($q) => $q->whereHas(
                    'textbookChapter.textbook',
                    fn ($inner) => $inner->where('grade_level_id', $grade->id),
                ),
            );
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
                'question_issue_reports_count' => 0,
                'question_issue_reports_pending_count' => 0,
                'question_issue_reports_sent_count' => 0,
                'locked_attempts_count' => 0,
                'content_publish_queue_count' => 0,
                'content_recheck_queue_count' => 0,
                'gemini_pending_count' => 0,
                'gemini_done_count' => 0,
            ],
            'students' => [],
            'helpRequests' => [],
            'questionIssueReports' => [],
            'questionIssueReportsSentToUploader' => [],
            'lockedAttempts' => [],
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
        ];
    }

    /**
     * In-progress attempts locked after too many tab/app leaves (current grade filter).
     *
     * @param  list<int>  $enrollmentIds
     * @return list<array<string, mixed>>
     */
    private function lockedAttemptsForEnrollments(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $candidates = SetAttempt::query()
            ->where('status', SetAttempt::STATUS_IN_PROGRESS)
            ->where('tab_leave_count', '>=', AttemptIntegrity::TAB_LEAVE_LOCK_LIMIT)
            ->whereHas(
                'assignment',
                fn ($q) => $q
                    ->whereIn('student_enrollment_id', $enrollmentIds)
                    ->where('status', '!=', SetAssignment::STATUS_CANCELLED),
            )
            ->with([
                'assignment.enrollment.student:id,name',
                'assignment.enrollment.gradeLevel:id,name,protect_test_attempts,protect_practice_attempts',
                'assignment.practiceSet:id,set_code,title,scope,tier',
            ])
            ->latest('updated_at')
            ->limit(50)
            ->get();

        return $candidates
            ->filter(fn (SetAttempt $attempt) => AttemptIntegrity::isLocked($attempt))
            ->map(function (SetAttempt $attempt) {
                $assignment = $attempt->assignment;
                $enrollment = $assignment?->enrollment;
                $set = $assignment?->practiceSet;
                $isTest = (bool) $set?->isChapterTest();

                return [
                    'attempt_id' => $attempt->id,
                    'assignment_id' => $assignment?->id,
                    'attempt_number' => $attempt->attempt_number,
                    'student_id' => $enrollment?->student_id,
                    'student_name' => $enrollment?->student?->name,
                    'class_name' => $enrollment?->gradeLevel?->name,
                    'set_code' => $set?->set_code,
                    'kind_label' => $isTest ? 'Test' : 'Practice',
                    'tab_leave_count' => (int) ($attempt->tab_leave_count ?? 0),
                    'tab_leave_lock_limit' => AttemptIntegrity::TAB_LEAVE_LOCK_LIMIT,
                    'updated_at' => $attempt->updated_at?->toDateTimeString(),
                ];
            })
            ->values()
            ->all();
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
     * @param  array<string, mixed>|null  $geminiProgress
     * @return array<string, mixed>
     */
    private function serializeDashboardContentTask(ContentUploadTask $task, ?array $geminiProgress = null): array
    {
        return [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'assignee_name' => $task->assignee?->name,
            'chapter_number' => $task->textbookChapter?->chapter_number,
            'chapter_title' => $task->textbookChapter?->title,
            'grade_name' => $task->textbookChapter?->textbook?->gradeLevel?->name,
            'textbook_name' => $task->textbookChapter?->textbook?->name,
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'published_at' => $task->published_at?->toIso8601String(),
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'gemini_progress' => $geminiProgress,
            'can_gemini_verify' => (bool) ($geminiProgress['can_gemini'] ?? false),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  array{upcoming: list<mixed>, past: list<mixed>}  $examPlans
     * @return array<string, int>
     */
    /**
     * Sets the student started but left unfinished — shown at the top of their dashboard.
     *
     * @param  list<array<string, mixed>>  $assignments
     * @return list<array<string, mixed>>
     */
    private function resumeItemsFromAssignments(array $assignments): array
    {
        $items = [];

        foreach ($assignments as $row) {
            $attemptId = $row['in_progress_attempt_id'] ?? null;
            $partial = $row['partial_progress'] ?? null;

            if (! $attemptId || ! is_array($partial)) {
                continue;
            }

            $remaining = (int) ($partial['remaining'] ?? 0);
            if ($remaining <= 0) {
                continue;
            }

            $done = (int) ($partial['done'] ?? 0);
            $total = max(1, (int) ($partial['total'] ?? 0));

            $items[] = [
                'assignment_id' => $row['assignment_id'] ?? null,
                'attempt_id' => $attemptId,
                'set_code' => $row['set_code'] ?? null,
                'kind_label' => $row['kind_label'] ?? 'Practice',
                'chapter_name' => $row['chapter_name'] ?? null,
                'topic_name' => $row['topic_name'] ?? null,
                'target_date' => $row['target_date'] ?? null,
                'is_overdue' => (bool) ($row['is_overdue'] ?? false),
                'delivery_mode' => $row['delivery_mode'] ?? 'online',
                'done' => $done,
                'total' => $total,
                'remaining' => $remaining,
                'progress_label' => (string) ($partial['label'] ?? "{$done}/{$total}"),
            ];
        }

        usort($items, static function (array $left, array $right): int {
            $byRemaining = $left['remaining'] <=> $right['remaining'];
            if ($byRemaining !== 0) {
                return $byRemaining;
            }

            return strcmp((string) ($left['target_date'] ?? '9999-12-31'), (string) ($right['target_date'] ?? '9999-12-31'));
        });

        return array_values($items);
    }

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
