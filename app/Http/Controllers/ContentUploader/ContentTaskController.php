<?php

namespace App\Http\Controllers\ContentUploader;

use App\Http\Controllers\Controller;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationRun;
use App\Services\ContentUploaderDashboardService;
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
        private ContentUploaderDashboardService $uploaderDashboard,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $dashboard = $this->uploaderDashboard->forUser($user);

        return Inertia::render('ContentUploader/Tasks/Index', $dashboard);
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
            'task' => $this->uploaderDashboard->serializeTask($contentTask) + [
                'admin_notes' => $contentTask->admin_notes,
                'can_work' => $contentTask->canAssigneeWork(),
                'awaiting_agreement' => $contentTask->isAwaitingAgreement(),
                'needs_review' => $contentTask->uploaderBucket() === 'review_pending',
                'textbook_chapter_id' => $contentTask->textbook_chapter_id,
            ],
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

    public function startReview(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        try {
            $this->taskService->startReview($contentTask, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('content.tasks.show', $contentTask)
            ->with('success', 'Review each question — fix options and explanations, then submit when done.');
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

    public function uploadVerificationDiagram(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'diagram' => ['required', 'image', 'max:5120'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $this->verificationService->attachDiagram(
                $run,
                (int) $validated['question_id'],
                $request->file('diagram'),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Figure uploaded for this question.');
    }

    public function removeVerificationDiagram(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $this->verificationService->removeDiagram(
                $run,
                (int) $validated['question_id'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Figure removed.');
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
}
