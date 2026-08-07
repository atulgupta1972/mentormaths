<?php

namespace App\Http\Controllers\ContentUploader;

use App\Http\Controllers\Controller;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationRun;
use App\Services\ContentUploadTaskService;
use App\Services\ContentVerificationService;
use App\Services\ContentWorkSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentTaskController extends Controller
{
    public function __construct(
        private ContentUploadTaskService $taskService,
        private ContentVerificationService $verificationService,
        private ContentWorkSessionService $sessionService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $tasks = ContentUploadTask::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with(['textbookChapter.textbook.gradeLevel'])
            ->latest()
            ->get()
            ->map(fn (ContentUploadTask $task) => $this->serializeTask($task));

        return Inertia::render('ContentUploader/Tasks/Index', [
            'tasks' => $tasks,
        ]);
    }

    public function show(Request $request, ContentUploadTask $contentTask): Response
    {
        $this->authorizeTask($contentTask, $request);

        $contentTask->load(['textbookChapter.textbook.gradeLevel']);

        $verification = null;
        if (in_array($contentTask->status, [
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
        ], true)) {
            $verification = $this->verificationService->forTask($contentTask, $request->user());
        }

        return Inertia::render('ContentUploader/Tasks/Show', [
            'task' => $this->serializeTask($contentTask, detailed: true),
            'verification' => $verification ? [
                'run_id' => $verification['run']->id,
                'questions' => $verification['questions'],
                'summary' => $verification['summary'],
            ] : null,
            'activeSeconds' => $this->sessionService->totalActiveSeconds($contentTask),
            'textbookChapterUrl' => route('content.textbooks.show', $contentTask->textbook_chapter_id),
        ]);
    }

    public function agree(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        try {
            $this->taskService->agree($contentTask, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Rate agreed. You can start work on this chapter.');
    }

    public function markUploaded(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        try {
            $this->taskService->markUploaded($contentTask, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Upload marked complete. Verify each question below.');
    }

    public function saveVerificationCheck(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'checks' => ['required', 'array'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $this->verificationService->saveCheck(
                $run,
                (int) $validated['question_id'],
                $validated['checks'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function saveVerificationQuestion(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'question_text' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'method_hint' => ['nullable', 'string', 'max:2000'],
            'difficulty' => ['nullable', 'string', 'max:64'],
            'options' => ['required', 'array', 'min:2', 'max:8'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_text' => ['required', 'string', 'max:2000'],
            'options.*.is_correct' => ['required', 'boolean'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $this->verificationService->saveQuestion(
                $run,
                (int) $validated['question_id'],
                $validated,
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Question saved and marked verified.');
    }

    public function completeVerification(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $this->verificationService->completeRun($run, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'All questions verified. Submit for admin publish when ready.');
    }

    public function submitForPublish(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        try {
            $this->taskService->submitForPublish($contentTask, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Submitted for admin publish. You will be notified when live.');
    }

    public function pingSession(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        if (! $contentTask->canAssigneeWork()) {
            return back();
        }

        $session = $this->sessionService->startOrResume($contentTask, $request->user());
        $this->sessionService->recordActivity($session);

        return back();
    }

    private function authorizeTask(ContentUploadTask $contentTask, Request $request): void
    {
        if ($contentTask->assigned_to_user_id !== $request->user()->id && ! $request->user()->isAdmin()) {
            abort(403);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(ContentUploadTask $task, bool $detailed = false): array
    {
        $chapter = $task->textbookChapter;

        $data = [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'agreed_at' => $task->agreed_at?->toIso8601String(),
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'textbook_name' => $chapter->textbook?->name,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
            ] : null,
        ];

        if ($detailed) {
            $data['admin_notes'] = $task->admin_notes;
            $data['can_work'] = $task->canAssigneeWork();
            $data['awaiting_agreement'] = $task->isAwaitingAgreement();
        }

        return $data;
    }
}
