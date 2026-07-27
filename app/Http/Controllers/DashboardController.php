<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Services\DashboardService;
use App\Support\MailConfigStatus;
use App\Support\StudentWeeklyReportEmails;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return Inertia::render('Dashboard', [
                'isAdmin' => true,
                'mailSettings' => MailConfigStatus::forAdmin(),
                'gradeLevels' => GradeLevel::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['id', 'name']),
                ...$this->dashboardService->forAdmin($request),
            ]);
        }

        $enrollment = $user->student?->currentEnrollment();
        $studentData = $this->dashboardService->forStudent($enrollment);
        $student = $user->student;

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            'weeklyReportEmails' => $student
                ? StudentWeeklyReportEmails::display($student->parent1_email, $student->parent2_email)
                : '',
            ...$studentData,
        ]);
    }
}
