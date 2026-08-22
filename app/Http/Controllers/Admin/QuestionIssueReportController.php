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

    public function returnToUploader(Request $request, QuestionIssueReport $report): RedirectResponse
    {
        abort_unless($report->isPendingAdmin(), 404);

        $validated = $request->validate([
            'issue' => ['required', 'in:wrong_answer,incomplete,other,question_correct'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        if ($validated['issue'] === 'question_correct') {
            try {
                $mail = $this->issueReports->confirmQuestionCorrectRequireReattempt(
                    $report,
                    $request->user(),
                    $validated['remark'] ?? null,
                );
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }

            $message = 'Question confirmed correct. Student must re-attempt — original score stays 0.';
            if ($mail['sent'] ?? false) {
                $message .= ' Email sent to '.$mail['email'].'.';
            } elseif (($mail['error'] ?? null) === 'no_email') {
                $message .= ' No student email on file — tell them on the dashboard.';
            } else {
                $message .= ' Email could not be sent — tell them on the dashboard.';
            }

            return back()->with('success', $message);
        }

        if ($validated['issue'] === 'other' && ! filled(trim((string) ($validated['remark'] ?? '')))) {
            return back()->with('error', 'Add a short note so the uploader knows what to fix.');
        }

        try {
            $this->issueReports->returnToUploader(
                $report,
                $request->user(),
                $validated['issue'],
                $validated['remark'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'This sum is on the uploader dashboard to correct. After they fix it, mark Fixed — return to student.');
    }
}
