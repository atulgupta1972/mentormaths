<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\Worksheet;
use App\Services\RevisionAssignmentService;
use App\Services\SetAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SelfAssignController extends Controller
{
    public function __construct(
        private SetAssignmentService $assignmentService,
        private RevisionAssignmentService $revisionService,
    ) {}

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

        // Start / continue revision mentoring (Rev 1 already auto-assigned; this starts R2+).
        if ($request->boolean('start_revision')) {
            $fromId = $request->integer('assignment_id') ?: null;
            $from = $fromId
                ? SetAssignment::query()
                    ->whereKey($fromId)
                    ->where('student_enrollment_id', $enrollment->id)
                    ->where('worksheet_id', $worksheet->id)
                    ->first()
                : SetAssignment::query()
                    ->where('student_enrollment_id', $enrollment->id)
                    ->where('worksheet_id', $worksheet->id)
                    ->where('revision_number', 0)
                    ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
                    ->orderByDesc('id')
                    ->first();

            if (! $from) {
                return back()->with('error', 'Finish the original sheet at 100% score before revision.');
            }

            try {
                $revision = $this->revisionService->startNextRevision($from, $user);
            } catch (\InvalidArgumentException $e) {
                return back()->with('error', $e->getMessage());
            }

            return redirect()
                ->route('student.assignments.show', $revision)
                ->with('success', "Revision {$revision->revision_number} is ready — open and start.");
        }

        $dueDate = now()->addDays(3)->toDateString();

        try {
            $assignment = $this->assignmentService->assign(
                $worksheet,
                $enrollment,
                $user,
                $dueDate,
                'Self-assigned from chapter summary',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            Log::error('Student self-assign failed.', [
                'worksheet_id' => $worksheet->id,
                'enrollment_id' => $enrollment->id,
                'message' => $e->getMessage(),
            ]);

            return back()->with('error', 'Could not assign this set. Please try again, or tell your teacher.');
        }

        return back()
            ->with('success', "{$worksheet->set_code} is on this chapter — use Open when you are ready.")
            ->with('assigned_sheet', [
                'worksheet_id' => $worksheet->id,
                'assignment_id' => $assignment->id,
            ]);
    }
}
