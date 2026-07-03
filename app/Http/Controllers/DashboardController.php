<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Services\SetAttemptService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private SetAttemptService $attemptService) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return Inertia::render('Dashboard', [
                'isAdmin' => true,
            ]);
        }

        $enrollment = $user->student?->currentEnrollment();
        $assignments = $enrollment
            ? $this->attemptService->dashboardForEnrollment($enrollment)
            : [];

        return Inertia::render('Dashboard', [
            'isAdmin' => false,
            'assignments' => $assignments,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
        ]);
    }
}
