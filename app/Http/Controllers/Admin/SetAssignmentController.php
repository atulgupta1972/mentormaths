<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\Worksheet;
use App\Services\SetAssignmentService;
use App\Support\AssignmentMailer;
use App\Support\AssignmentProgress;
use App\Support\AttemptResultSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetAssignmentController extends Controller
{
    public function __construct(private SetAssignmentService $assignmentService) {}

    public function show(SetAssignment $assignment): Response
    {
        $assignment->load([
            'enrollment.student:id,name',
            'practiceSet.topic.chapter',
            'practiceSet' => fn ($q) => $q->withCount('questions'),
            'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            'assigner:id,name',
        ]);

        $latest = $assignment->attempts->first();
        $latestSummary = ($latest && $latest->status === SetAttempt::STATUS_SUBMITTED)
            ? AttemptResultSummary::forAdmin($latest)
            : null;

        return Inertia::render('Admin/Assignments/Show', [
            'assignment' => [
                ...AssignmentProgress::formatAssignmentSummary($assignment, $latest),
                'notes' => $assignment->notes,
                'student_name' => $assignment->enrollment->student->name,
                'assigned_by' => $assignment->assigner?->name,
            ],
            'attempts' => $assignment->attempts->map(fn ($a) => [
                'id' => $a->id,
                'attempt_number' => $a->attempt_number,
                'status' => $a->status,
                'score' => $a->score,
                'max_score' => $a->max_score,
                'time_seconds' => $a->time_seconds,
                'submission_timing' => $a->submission_timing,
                'started_at' => $a->started_at?->toDateTimeString(),
                'completed_at' => $a->completed_at?->toDateTimeString(),
            ]),
            'latestResult' => $latestSummary,
        ]);
    }

    public function store(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
            'exam_plan_id' => ['nullable', 'exists:exam_plans,id'],
        ]);

        $student = Student::findOrFail($validated['student_id']);
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'Student has no active enrollment for the current year.');
        }

        try {
            $this->assignmentService->assign(
                $worksheet,
                $enrollment,
                $request->user(),
                $validated['target_date'],
                $validated['notes'] ?? null,
                $validated['exam_plan_id'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $emailResult = AssignmentMailer::sendAssigned(
            $student,
            $worksheet,
            $validated['target_date'],
            $validated['notes'] ?? null,
        );

        $message = "Assigned {$worksheet->set_code} to {$student->name}. Target: {$validated['target_date']}."
            .(AssignmentMailer::flashSuffixForSingle($emailResult, $student->name) ?? '');

        return $this->assignmentRedirect($message, $emailResult);
    }

    public function storeBulk(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
            'board_id' => ['nullable', 'exists:boards,id'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->assignmentService->assignToActiveYearClass(
                $worksheet,
                $request->user(),
                $validated['target_date'],
                $validated['grade_level_id'] ?? null,
                $validated['notes'] ?? null,
                $validated['board_id'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $emailCounts = AssignmentMailer::sendBulkAssigned(
            $result['assignedStudents'] ?? [],
            $worksheet,
            $validated['target_date'],
            $validated['notes'] ?? null,
        );

        $message = "Assigned {$worksheet->set_code} to {$result['assigned']} student(s). Target: {$validated['target_date']}.";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']}.";
        }

        $message .= AssignmentMailer::flashSuffixForBulk($emailCounts) ?? '';

        $redirect = back()->with('success', $message);

        $warnings = [];
        if ($result['errors']) {
            $warnings[] = implode(' ', array_slice($result['errors'], 0, 3));
        }
        if ($emailCounts['skipped'] > 0 && $emailCounts['sent'] === 0) {
            $warnings[] = 'Add student emails on their profiles to notify by email.';
        }
        if ($emailCounts['via_log'] ?? false) {
            $warnings[] = 'MAIL_MAILER=log on server — emails are not delivered. Set SMTP in .env and run php artisan config:cache.';
        }

        if ($warnings !== []) {
            $redirect = $redirect->with('warning', implode(' ', $warnings));
        }

        return $redirect;
    }

    public function storeStudents(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'exists:students,id'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->assignmentService->assignToStudents(
                $worksheet,
                $validated['student_ids'],
                $request->user(),
                $validated['target_date'],
                $validated['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $emailCounts = AssignmentMailer::sendBulkAssigned(
            $result['assignedStudents'] ?? [],
            $worksheet,
            $validated['target_date'],
            $validated['notes'] ?? null,
        );

        $message = "Assigned {$worksheet->set_code} to {$result['assigned']} student(s). Target: {$validated['target_date']}.";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']}.";
        }

        $message .= AssignmentMailer::flashSuffixForBulk($emailCounts) ?? '';

        $redirect = back()->with('success', $message);

        $warnings = [];
        if ($result['errors']) {
            $warnings[] = implode(' ', array_slice($result['errors'], 0, 3));
        }
        if ($emailCounts['skipped'] > 0 && $emailCounts['sent'] === 0) {
            $warnings[] = 'Add student emails on their profiles to notify by email.';
        }
        if ($emailCounts['via_log'] ?? false) {
            $warnings[] = 'MAIL_MAILER=log on server — emails are not delivered. Set SMTP in .env and run php artisan config:cache.';
        }

        if ($warnings !== []) {
            $redirect = $redirect->with('warning', implode(' ', $warnings));
        }

        return $redirect;
    }

    public function reassign(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'target_date' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $this->assignmentService->reassign(
                $assignment,
                $request->user(),
                $validated['target_date'],
                $validated['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $assignment->load([
            'enrollment.student',
            'practiceSet',
        ]);

        $student = $assignment->enrollment->student;
        $emailResult = AssignmentMailer::sendAssigned(
            $student,
            $assignment->practiceSet,
            $validated['target_date'],
            $validated['notes'] ?? null,
        );

        $message = 'Re-assigned with new target date. Student can attempt again.'
            .(AssignmentMailer::flashSuffixForSingle($emailResult, $student->name) ?? '');

        return $this->assignmentRedirect($message, $emailResult);
    }

    /**
     * @param  array{sent: bool, email: ?string, error: ?string}  $emailResult
     */
    private function assignmentRedirect(string $success, array $emailResult): RedirectResponse
    {
        $redirect = back()->with('success', $success);

        if (! $emailResult['sent'] && $emailResult['error'] === 'no_email') {
            return $redirect->with(
                'warning',
                'Assignment saved. Add an email on the student profile to send notifications automatically.',
            );
        }

        if (! $emailResult['sent'] && $emailResult['error'] === 'send_failed') {
            return $redirect->with('warning', 'Assignment saved but the email could not be sent. Check mail settings.');
        }

        if ($emailResult['sent'] && ! empty($emailResult['via_log'])) {
            return $redirect->with(
                'warning',
                'Assignment saved. Email was written to the server log only (MAIL_MAILER=log). Configure SMTP in .env to deliver real emails.',
            );
        }

        return $redirect;
    }
}
