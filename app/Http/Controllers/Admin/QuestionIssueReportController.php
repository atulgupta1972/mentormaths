<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuestionIssueReport;
use App\Services\QuestionIssueReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class QuestionIssueReportController extends Controller
{
    public function __construct(
        private QuestionIssueReportService $issueReports,
    ) {}

    public function markFixed(Request $request, QuestionIssueReport $report): RedirectResponse
    {
        abort_unless($report->isPendingAdmin(), 404);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->issueReports->markFixedAndReturnToStudent(
                $report,
                $request->user(),
                $validated['admin_note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Sum marked fixed. It is back on the student’s correction list to attempt again.');
    }

    public function dismiss(Request $request, QuestionIssueReport $report): RedirectResponse
    {
        abort_unless($report->isPendingAdmin(), 404);

        $validated = $request->validate([
            'admin_note' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->issueReports->dismiss(
                $report,
                $request->user(),
                $validated['admin_note'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Report dismissed. The student will not be asked to re-attempt this sum from this report.');
    }
}
