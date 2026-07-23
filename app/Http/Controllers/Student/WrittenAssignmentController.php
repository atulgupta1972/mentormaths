<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\Worksheet;
use App\Models\WrittenSubmission;
use App\Services\WrittenSubmissionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WrittenAssignmentController extends Controller
{
    public function __construct(private WrittenSubmissionService $submissionService) {}

    public function show(Request $request, SetAssignment $assignment): Response|RedirectResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $assignment->load([
            'practiceSet' => fn ($q) => $q->withCount('questions'),
        ]);

        $worksheet = $assignment->practiceSet;

        if (! $worksheet->isWritten()) {
            return redirect()->route('student.assignments.show', $assignment);
        }

        return Inertia::render('Student/WrittenSheets/Assignment', [
            'assignment' => [
                'id' => $assignment->id,
                'status' => $assignment->status,
                'notes' => $assignment->notes,
                'target_date' => $assignment->due_date?->toDateString(),
                'is_overdue' => $assignment->isOverdue(),
                'practice_set' => [
                    'set_code' => $worksheet->set_code,
                    'kind_label' => $worksheet->isChapterTest() ? 'Written test' : 'Written practice',
                    'questions_count' => $worksheet->questions_count,
                    'download_url' => route('student.written-assignments.download', $assignment),
                ],
                'submission' => $this->submissionService->payloadForAssignment($assignment),
            ],
        ]);
    }

    public function storeUpload(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        try {
            $this->submissionService->store($assignment, $validated['files']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Work uploaded. Your teacher will check it and enter marks.');
    }

    public function download(Request $request, SetAssignment $assignment): StreamedResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $worksheet = $assignment->practiceSet;

        abort_unless($worksheet->isWritten() && $worksheet->written_pdf_path, 404);

        return Storage::disk('public')->download(
            $worksheet->written_pdf_path,
            ($worksheet->set_code ?: 'written-sheet').'.pdf',
        );
    }

    private function authorizeAssignment(Request $request, SetAssignment $assignment): void
    {
        $student = $request->user()->student;
        $enrollment = $student?->currentEnrollment();

        abort_unless(
            $enrollment && $assignment->student_enrollment_id === $enrollment->id,
            403,
        );
    }
}
