<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\Worksheet;
use App\Services\ClassCoverageService;
use App\Services\SetAssignmentService;
use App\Services\SetAttemptService;
use App\Support\AssignmentMailer;
use App\Support\AssignmentProgress;
use App\Support\AttemptIntegrity;
use App\Support\AttemptResultSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SetAssignmentController extends Controller
{
    public function __construct(
        private SetAssignmentService $assignmentService,
        private SetAttemptService $attemptService,
        private ClassCoverageService $classCoverage,
    ) {}

    public function show(SetAssignment $assignment): Response|RedirectResponse
    {
        $assignment->load([
            'enrollment.student:id,name',
            'enrollment.gradeLevel:id,name',
            'enrollment.board:id,name,code',
            'practiceSet.topic.chapter.syllabusVersion.board:id,name,code',
            'practiceSet.chapter.syllabusVersion.board:id,name,code',
            'practiceSet' => fn ($q) => $q->withCount('questions'),
            'effectiveChapter:id,name,chapter_number',
            'attempts' => fn ($q) => $q->orderByDesc('attempt_number'),
            'assigner:id,name',
        ]);

        if ($assignment->practiceSet->isWritten()) {
            return redirect()->route('admin.written-sheets.show', [
                'worksheet' => $assignment->worksheet_id,
                'student_id' => $assignment->enrollment->student_id,
                'assignment_id' => $assignment->id,
            ]);
        }

        $latest = $assignment->attempts->first();
        $latestSummary = ($latest && $latest->status === SetAttempt::STATUS_SUBMITTED)
            ? AttemptResultSummary::forAdmin($latest)
            : null;

        $sourceChapter = $assignment->practiceSet->isChapterScope()
            ? $assignment->practiceSet->chapter
            : $assignment->practiceSet->topic?->chapter;

        $effectiveId = $this->classCoverage->resolveEffectiveSyllabusChapterId(
            $assignment->practiceSet,
            $assignment->enrollment,
            $assignment,
        );

        $homeChapters = $this->classCoverage->homeChapterOptionsForEnrollment($assignment->enrollment);

        return Inertia::render('Admin/Assignments/Show', [
            'assignment' => [
                ...AssignmentProgress::formatAssignmentSummary($assignment, $latest),
                'notes' => $assignment->notes,
                'student_name' => $assignment->enrollment->student->name,
                'student_class' => trim(implode(' · ', array_filter([
                    $assignment->enrollment->gradeLevel?->name,
                    $assignment->enrollment->board?->code,
                ]))),
                'assigned_by' => $assignment->assigner?->name,
                'source_chapter_label' => $sourceChapter
                    ? trim(implode(' · ', array_filter([
                        $sourceChapter->syllabusVersion?->board?->code,
                        $sourceChapter->name,
                    ])))
                    : null,
                'effective_syllabus_chapter_id' => $assignment->effective_syllabus_chapter_id
                    ? (int) $assignment->effective_syllabus_chapter_id
                    : null,
                'resolved_syllabus_chapter_id' => $effectiveId,
                'resolved_chapter_label' => collect($homeChapters)
                    ->firstWhere('id', $effectiveId)['label'] ?? null,
            ],
            'homeChapters' => $homeChapters,
            'attempts' => $assignment->attempts->map(function ($a) {
                $locked = AttemptIntegrity::isLocked($a);

                return [
                    'id' => $a->id,
                    'attempt_number' => $a->attempt_number,
                    'status' => $a->status,
                    'score' => $a->score,
                    'max_score' => $a->max_score,
                    'time_seconds' => $a->time_seconds,
                    'submission_timing' => $a->submission_timing,
                    'tab_leave_count' => $a->tab_leave_count ?? 0,
                    'tab_leave_lock_limit' => AttemptIntegrity::TAB_LEAVE_LOCK_LIMIT,
                    'locked' => $locked,
                    'can_unlock' => $locked && $a->status === SetAttempt::STATUS_IN_PROGRESS,
                    'started_at' => $a->started_at?->toDateTimeString(),
                    'completed_at' => $a->completed_at?->toDateTimeString(),
                ];
            }),
            'latestResult' => $latestSummary,
            'tabLeaveLockLimit' => AttemptIntegrity::TAB_LEAVE_LOCK_LIMIT,
        ]);
    }

    public function updateEffectiveChapter(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $validated = $request->validate([
            'effective_syllabus_chapter_id' => ['nullable', 'integer', 'exists:syllabus_chapters,id'],
        ]);

        try {
            $this->assignmentService->updateEffectiveChapter(
                $assignment,
                isset($validated['effective_syllabus_chapter_id'])
                    ? (int) $validated['effective_syllabus_chapter_id']
                    : null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'Study-plan chapter updated for this assignment.');
    }

    public function unlockAttempt(SetAttempt $attempt): RedirectResponse
    {
        $assignment = $attempt->assignment;
        abort_unless($assignment, 404);

        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS) {
            return back()->with('error', 'Only an in-progress attempt can be unlocked.');
        }

        $this->attemptService->unlockIntegrityLock($attempt);

        $assignment->loadMissing('enrollment.student');
        $studentName = $assignment->enrollment?->student?->name;
        $label = $studentName
            ? "{$studentName} — attempt #{$attempt->attempt_number}"
            : "Attempt #{$attempt->attempt_number}";

        return redirect()
            ->back(fallback: route('admin.set-assignments.show', $assignment))
            ->with('success', "{$label} unlocked. Tab leaves reset to 0 — student can continue.");
    }

    public function store(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
            'exam_plan_id' => ['nullable', 'exists:exam_plans,id'],
            'effective_syllabus_chapter_id' => ['nullable', 'integer', 'exists:syllabus_chapters,id'],
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
                isset($validated['effective_syllabus_chapter_id'])
                    ? (int) $validated['effective_syllabus_chapter_id']
                    : null,
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

    public function destroy(SetAssignment $assignment): RedirectResponse
    {
        $assignment->load(['enrollment.student', 'practiceSet']);

        try {
            $this->assignmentService->cancel($assignment);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $studentName = $assignment->enrollment->student->name;
        $setCode = $assignment->practiceSet->set_code;

        return back()->with('success', "Removed {$setCode} assignment for {$studentName}.");
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
