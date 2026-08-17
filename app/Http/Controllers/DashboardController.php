<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Services\ClassCoverageService;
use App\Services\ContentUploaderDashboardService;
use App\Services\DashboardService;
use App\Support\MailConfigStatus;
use App\Support\StudentWeeklyReportEmails;
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
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            try {
                $adminDashboard = $this->dashboardService->forAdmin($request);
            } catch (Throwable $e) {
                Log::error('Admin dashboard failed to load.', ['message' => $e->getMessage()]);
                $adminDashboard = $this->dashboardService->emptyAdminPayload($request);
            }

            return Inertia::render('Dashboard', [
                'isAdmin' => true,
                'mailSettings' => MailConfigStatus::forAdmin(),
                'gradeLevels' => GradeLevel::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name']),
                ...$adminDashboard,
            ]);
        }

        if ($user->isContentUploader() && ! $user->student) {
            return redirect()->route('content.tasks.index');
        }

        $enrollment = $user->student?->currentEnrollment();
        $enrollment?->loadMissing(['gradeLevel:id,name', 'board:id,name']);
        $gradeLevelId = $request->integer('grade_level_id') ?: null;
        $boardId = $request->integer('board_id') ?: null;
        $studentData = $this->dashboardService->forStudent($enrollment, $gradeLevelId, $boardId);
        $student = $user->student;
        $classCoverage = $this->classCoverage->forEnrollment($enrollment);

        $contentUploaderTasks = null;
        if ($user->isContentUploader()) {
            $dashboard = $this->uploaderDashboard->forUser($user);
            $contentUploaderTasks = [
                'summary' => $dashboard['summary'],
                'uploadPending' => $dashboard['uploadPending'],
                'reviewPending' => $dashboard['reviewPending'],
            ];
        }

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            'weeklyReportEmails' => $student
                ? StudentWeeklyReportEmails::display($student->parent1_email, $student->parent2_email)
                : '',
            'contentUploaderTasks' => $contentUploaderTasks,
            'classCoverage' => $classCoverage,
            'studyPlanContext' => [
                'grade_name' => $enrollment?->gradeLevel?->name,
                'board_name' => $enrollment?->board?->name,
            ],
            ...$studentData,
        ]);
    }
}
