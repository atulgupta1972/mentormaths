<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\Student;
use App\Models\Worksheet;
use App\Services\SetAssignmentService;
use App\Support\AssignmentProgress;
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
        ]);
    }

    public function store(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'exists:students,id'],
            'target_date' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string'],
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
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Assigned {$worksheet->set_code} to {$student->name}. Target: {$validated['target_date']}.");
    }

    public function storeBulk(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $validated = $request->validate([
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
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
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Assigned {$worksheet->set_code} to {$result['assigned']} student(s). Target: {$validated['target_date']}.";

        if ($result['skipped'] > 0) {
            $message .= " Skipped {$result['skipped']}.";
        }

        return back()
            ->with('success', $message)
            ->with('warning', $result['errors'] ? implode(' ', array_slice($result['errors'], 0, 3)) : null);
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

        return back()->with('success', 'Re-assigned with new target date. Student can attempt again.');
    }
}
