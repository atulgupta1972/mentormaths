<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Worksheet;
use App\Services\SetAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SelfAssignController extends Controller
{
    public function __construct(private SetAssignmentService $assignmentService) {}

    public function store(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $user = $request->user();
        $enrollment = $user->student?->currentEnrollment();

        if (! $enrollment) {
            abort(403, 'No active enrollment found.');
        }

        if ($user->isAdmin()) {
            abort(403, 'Only students can self-assign from the dashboard.');
        }

        $dueDate = now()->addDays(3)->toDateString();

        $this->assignmentService->assign(
            $worksheet,
            $enrollment,
            $user,
            $dueDate,
            'Self-assigned from chapter summary',
        );

        return redirect()
            ->route('dashboard')
            ->with('success', "{$worksheet->set_code} added to your work — open it from To do or the chapter summary.");
    }
}
