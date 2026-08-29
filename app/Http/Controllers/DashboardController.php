<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Student;
use App\Services\ClassCoverageService;
use App\Services\ContentUploaderDashboardService;
use App\Services\DashboardService;
use App\Services\SetAttemptService;
use App\Support\MailConfigStatus;
use App\Support\StudentWeeklyReportEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DashboardController extends Controller
{
    public function __construct(
        private DashboardService $dashboardService,
        private ContentUploaderDashboardService $uploaderDashboard,
        private ClassCoverageService $classCoverage,
        private SetAttemptService $attemptService,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            try {
                $adminDashboard = $this->dashboardService->forAdmin($request);
            } catch (Throwable $e) {
                Log::error('Admin dashboard failed to load.', ['message' => $e->getMessage()]);
                $adminDashboard = [
                    ...$this->dashboardService->emptyAdminPayload($request),
                    'loadError' => $e->getMessage(),
                ];
            }

            try {
                $mailSettings = MailConfigStatus::forAdmin();
            } catch (Throwable $e) {
                Log::error('Admin dashboard failed to load mail settings.', ['message' => $e->getMessage()]);
                $mailSettings = null;
            }

            try {
                $gradeLevels = GradeLevel::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name'])
                    ->map(fn (GradeLevel $grade) => $grade->only(['id', 'name']))
                    ->all();
            } catch (Throwable $e) {
                Log::error('Admin dashboard failed to load grade levels.', ['message' => $e->getMessage()]);
                $gradeLevels = [];
            }

            try {
                return Inertia::render('Dashboard', [
                    'isAdmin' => true,
                    'mailSettings' => $mailSettings,
                    'gradeLevels' => $gradeLevels,
                    ...$adminDashboard,
                    'contentPublishQueue' => Inertia::defer(
                        fn () => $this->dashboardService->contentPublishQueueForAdmin($request),
                    ),
                    'contentRecheckQueue' => Inertia::defer(
                        fn () => $this->dashboardService->contentRecheckQueueForAdmin($request),
                    ),
                ]);
            } catch (Throwable $e) {
                Log::error('Admin dashboard failed to render.', ['message' => $e->getMessage()]);

                return Inertia::render('Dashboard', [
                    'isAdmin' => true,
                    'mailSettings' => null,
                    'gradeLevels' => [],
                    'loadError' => $e->getMessage(),
                    ...$this->dashboardService->emptyAdminPayload($request),
                    'contentPublishQueue' => Inertia::defer(fn () => []),
                    'contentRecheckQueue' => Inertia::defer(fn () => []),
                ]);
            }
        }

        if ($user->isContentUploader() && ! $user->student) {
            return redirect()->route('content.tasks.index');
        }

        if ($user->isMentor() && ! $user->isAdmin()) {
            return redirect()->route('mentor.classes.index');
        }

        $enrollment = $user->student?->currentEnrollment();
        $enrollment?->loadMissing(['gradeLevel:id,name', 'board:id,name']);
        $gradeLevelId = $request->integer('grade_level_id') ?: null;
        $boardId = $request->integer('board_id') ?: null;

        $loadError = null;
        $studentData = $this->dashboardService->forStudent(null);

        try {
            $studentData = $this->dashboardService->forStudent(
                $enrollment,
                $gradeLevelId,
                $boardId,
                includeAssignmentList: false,
            );
        } catch (Throwable $e) {
            Log::error('Student dashboard failed to load assignments.', [
                'user_id' => $user->id,
                'enrollment_id' => $enrollment?->id,
                'message' => $e->getMessage(),
            ]);
            $loadError = 'Some of your work could not be loaded. Please try again in a few minutes or tell your teacher.';
        }

        $classCoverageDeferred = Inertia::defer(function () use ($enrollment, $user) {
            try {
                return $this->classCoverage->forEnrollment($enrollment);
            } catch (Throwable $e) {
                Log::error('Student dashboard failed to load study plan.', [
                    'user_id' => $user->id,
                    'enrollment_id' => $enrollment?->id,
                    'message' => $e->getMessage(),
                ]);

                return array_merge(ClassCoverageService::emptyPayload(), [
                    'load_error' => 'Your study plan could not be loaded. Please try again in a few minutes or tell your teacher.',
                ]);
            }
        });

        $student = $user->student;

        $contentUploaderTasks = null;
        if ($user->isContentUploader()) {
            $dashboard = $this->uploaderDashboard->forUser($user);
            $contentUploaderTasks = [
                'summary' => $dashboard['summary'],
                'uploadPending' => $dashboard['uploadPending'],
                'reviewPending' => $dashboard['reviewPending'],
                'correctionsPending' => $dashboard['correctionsPending'],
                'geminiPending' => $dashboard['geminiPending'],
                'geminiDone' => $dashboard['geminiDone'],
            ];
        }

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            'weeklyReportEmails' => $student
                ? StudentWeeklyReportEmails::display($student->parent1_email, $student->parent2_email)
                : '',
            'contentUploaderTasks' => $contentUploaderTasks,
            'classCoverage' => $classCoverageDeferred,
            'studyPlanContext' => [
                'grade_name' => $enrollment?->gradeLevel?->name,
                'board_name' => $enrollment?->board?->name,
            ],
            'loadError' => $loadError,
            'assignments' => Inertia::defer(function () use ($enrollment) {
                if (! $enrollment) {
                    return [];
                }

                return $this->attemptService->dashboardForEnrollment($enrollment);
            }),
            ...$studentData,
        ]);
    }

    public function student(Request $request, Student $student): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            abort(404);
        }

        $enrollment->loadMissing(['student:id,name', 'gradeLevel:id,name']);

        try {
            return response()->json($this->dashboardService->adminStudentDetail($enrollment));
        } catch (Throwable $e) {
            Log::error('Admin dashboard failed to load student detail.', [
                'student_id' => $student->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Could not load this student.',
            ], 500);
        }
    }
}
