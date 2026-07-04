<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Services\DashboardService;
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
                ...$this->dashboardService->forAdmin($request),
            ]);
        }

        $enrollment = $user->student?->currentEnrollment();
        $studentData = $this->dashboardService->forStudent($enrollment);

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            ...$studentData,
        ]);
    }
}
