<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SetAssignment;
use App\Models\Worksheet;
use App\Models\WrittenSubmission;
use App\Services\SetAttemptService;
use App\Services\WrittenSubmissionService;
use App\Support\WrittenSubmissionLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WrittenAssignmentController extends Controller
{
    public function __construct(
        private WrittenSubmissionService $submissionService,
        private SetAttemptService $attemptService,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $student = $request->user()->student;
        $enrollment = $student?->currentEnrollment();

        if (! $enrollment) {
            return redirect()->route('dashboard')->with('error', 'No active enrollment found.');
        }

        $assignments = collect($this->attemptService->dashboardForEnrollment($enrollment))
            ->filter(fn (array $row) => ($row['delivery_mode'] ?? null) === 'written')
            ->values()
            ->all();

        $buckets = [
            'upload_pending' => collect($assignments)->filter(
                fn (array $row) => ! in_array($row['status'], ['green', 'green-late', 'checking'], true),
            )->values()->all(),
            'under_review' => collect($assignments)->filter(
                fn (array $row) => ($row['status'] ?? null) === 'checking',
            )->values()->all(),
            'done' => collect($assignments)->filter(
                fn (array $row) => in_array($row['status'], ['green', 'green-late'], true),
            )->values()->all(),
        ];

        return Inertia::render('Student/WrittenSheets/Index', [
            'buckets' => $buckets,
            'counts' => [
                'upload_pending' => count($buckets['upload_pending']),
                'under_review' => count($buckets['under_review']),
                'done' => count($buckets['done']),
                'total' => count($assignments),
            ],
        ]);
    }

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
                    'set_number' => $worksheet->set_number ?: 1,
                    'kind_label' => $worksheet->isChapterTest() ? 'Written test' : 'Written practice',
                    'questions_count' => $worksheet->questions_count,
                    'download_url' => route('student.written-assignments.download', $assignment),
                ],
                'submission' => $this->submissionService->payloadForAssignment($assignment),
            ],
            'upload_limits' => [
                'max_files' => WrittenSubmissionLimits::MAX_FILES,
                'max_file_mb' => (int) (WrittenSubmissionLimits::MAX_FILE_KB / 1024),
            ],
        ]);
    }

    public function storeUpload(Request $request, SetAssignment $assignment): RedirectResponse
    {
        $this->authorizeAssignment($request, $assignment);

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', WrittenSubmissionLimits::maxFilesRule()],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf', WrittenSubmissionLimits::maxFileSizeRule()],
        ], [
            'files.required' => 'Choose at least one photo or PDF.',
            'files.max' => 'Upload up to '.WrittenSubmissionLimits::MAX_FILES.' files at once.',
            'files.*.max' => 'Each file must be under '.(WrittenSubmissionLimits::MAX_FILE_KB / 1024).' MB.',
            'files.*.mimes' => 'Only JPG, PNG, WEBP, or PDF files are allowed.',
        ]);

        $hadCheckedResult = WrittenSubmission::query()
            ->where('set_assignment_id', $assignment->id)
            ->whereIn('status', [
                WrittenSubmission::STATUS_GRADED,
                WrittenSubmission::STATUS_FAILED,
            ])
            ->exists();

        try {
            $this->submissionService->store($assignment, $validated['files']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = $hadCheckedResult
            ? 'Re-upload received. Write answers in Q1, Q2, Q3… order on your sheet and upload photos in page order. We will email you when checking is finished.'
            : 'Work uploaded. We will email you when checking is finished — continue with your other work on the dashboard.';

        return redirect()->route('dashboard')->with('success', $message);
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
