<?php

namespace App\Http\Controllers\ContentUploader;

use App\Http\Controllers\Controller;
use App\Models\ContentQuestionCorrection;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationRun;
use App\Models\QuestionBlankAnswer;
use App\Services\ContentAiVerificationService;
use App\Services\GeminiPasteVerificationService;
use App\Services\ContentUploaderDashboardService;
use App\Services\ContentUploadTaskService;
use App\Services\ContentVerificationService;
use App\Services\ContentWorkSessionService;
use App\Services\FillBlankConversionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContentTaskController extends Controller
{
    public function __construct(
        private ContentUploadTaskService $taskService,
        private ContentVerificationService $verificationService,
        private ContentAiVerificationService $aiVerificationService,
        private GeminiPasteVerificationService $geminiPasteService,
        private ContentWorkSessionService $sessionService,
        private ContentUploaderDashboardService $uploaderDashboard,
        private FillBlankConversionService $fillBlankConversion,
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

        if ($contentTask->isFillBlankConversion()) {
            return $this->convert($request, $contentTask);
        }

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
                'has_pdf' => $contentTask->textbookChapter
                    ? app(\App\Services\TextbookChapterBookService::class)->hasStoredPdf($contentTask->textbookChapter)
                    : false,
            ],
            'verification' => $verification ? [
                'run_id' => $verification['run']->id,
                'questions' => $verification['questions'],
                'summary' => $verification['summary'],
                'gemini_prompt' => $this->geminiPasteService->buildPrompt(
                    collect($verification['questions'])
                        ->filter(fn (array $row) => ! $this->verificationService->isGeminiDoneRow($row))
                        ->values()
                        ->all(),
                    $this->geminiPasteService->chapterLabel($contentTask),
                ),
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

        return redirect()
            ->route($contentTask->isFillBlankConversion() ? 'content.tasks.convert' : 'content.tasks.show', $contentTask)
            ->with('success', $contentTask->isFillBlankConversion()
                ? 'Rate agreed. Convert each MCQ, Check as a student, then submit.'
                : 'Rate agreed. You can start work on this chapter.');
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

    public function startCorrection(Request $request, ContentQuestionCorrection $correction): RedirectResponse
    {
        $correction->loadMissing('task');
        abort_unless($correction->task, 404);
        $this->authorizeTask($correction->task, $request);

        return redirect()->route('content.corrections.edit', $correction);
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

    public function skipVerificationQuestion(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'skip_reason' => ['nullable', 'string', 'max:500'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $this->verificationService->skipQuestion(
                $run,
                (int) $validated['question_id'],
                $request->user(),
                $validated['skip_reason'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Question skipped — it will not count toward your payment.');
    }

    public function unskipVerificationQuestion(Request $request, ContentUploadTask $contentTask): RedirectResponse
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
            $this->verificationService->unskipQuestion(
                $run,
                (int) $validated['question_id'],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Skip cleared — verify or skip again.');
    }

    public function aiReviewVerification(Request $request, ContentUploadTask $contentTask): RedirectResponse
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
            $result = $this->aiVerificationService->reviewAndApply(
                $contentTask,
                $run,
                $request->user(),
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = sprintf(
            'AI reviewed %d question(s): %d approved, %d skipped (not paid), %d need your attention.',
            $result['reviewed'],
            $result['approved'],
            $result['skipped'],
            $result['needs_attention'],
        );

        if ($result['reviewed'] === 0) {
            $message = 'Nothing left to AI-review — all questions are already verified or skipped.';
        }

        return back()
            ->with('success', $message)
            ->with('ai_review', $result);
    }

    public function geminiPasteVerification(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'gemini_paste' => ['required', 'string', 'min:20'],
        ]);

        $run = ContentVerificationRun::query()->findOrFail($validated['run_id']);

        if ($run->content_upload_task_id !== $contentTask->id) {
            abort(403);
        }

        try {
            $result = $this->geminiPasteService->applyPaste(
                $contentTask,
                $run,
                $request->user(),
                $validated['gemini_paste'],
            );
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($result['reviewed'] === 0 && $result['unparsed'] === 0) {
            return back()->with('success', 'Nothing left to review — all questions are already verified or skipped.');
        }

        $message = sprintf(
            'Gemini review applied: %d verified, %d skipped, %d need your fix.',
            $result['approved'],
            $result['skipped'],
            $result['needs_attention'],
        );

        if ($result['unparsed'] > 0) {
            $message .= sprintf(' %d question(s) were missing from the paste.', $result['unparsed']);
        }

        return back()
            ->with('success', $message)
            ->with('gemini_review', $result);
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
            if ($contentTask->isFillBlankConversion()) {
                $this->fillBlankConversion->submit($contentTask, $request->user());
            } else {
                $this->taskService->submitForPublish($contentTask, $request->user());
            }
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $contentTask->isFillBlankConversion()
            ? 'Submitted. Admin will publish fill-in-blank and written sets.'
            : 'Submitted for admin publish. You will be notified when live.');
    }

    public function convert(Request $request, ContentUploadTask $contentTask): Response
    {
        $this->authorizeTask($contentTask, $request);
        abort_unless($contentTask->isFillBlankConversion(), 404);

        $contentTask->load(['textbookChapter.textbook.gradeLevel']);
        $rows = $this->fillBlankConversion->rows($contentTask->textbookChapter);
        $included = collect($rows)->where('skipped', false);
        $checked = $included->where('checked', true)->count();

        return Inertia::render('ContentUploader/Tasks/FillBlankConvert', [
            'task' => $this->uploaderDashboard->serializeTask($contentTask) + [
                'admin_notes' => $contentTask->admin_notes,
                'can_work' => $contentTask->canAssigneeWork(),
                'awaiting_agreement' => $contentTask->isAwaitingAgreement(),
                'textbook_chapter_id' => $contentTask->textbook_chapter_id,
            ],
            'rows' => $rows,
            'progress' => [
                'total' => count($rows),
                'included' => $included->count(),
                'checked' => $checked,
                'skipped' => collect($rows)->where('skipped', true)->count(),
            ],
            'formats' => collect([
                QuestionBlankAnswer::FORMAT_INTEGER,
                QuestionBlankAnswer::FORMAT_DECIMAL,
                QuestionBlankAnswer::FORMAT_FRACTION,
            ])->map(fn (string $format) => [
                'value' => $format,
                'label' => app(\App\Support\AnswerValidationService::class)->formatLabel($format),
            ])->values()->all(),
            'activeSeconds' => $this->sessionService->totalActiveSeconds($contentTask),
        ]);
    }

    public function saveConversionRow(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $this->validatedConversionDraft($request);

        try {
            $this->fillBlankConversion->saveDraft($contentTask, (int) $validated['index'], $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function checkConversionRow(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $this->validatedConversionDraft($request);
        $validated['attempt'] = $request->validate([
            'attempt' => ['required', 'string', 'max:500'],
        ])['attempt'];

        try {
            $result = $this->fillBlankConversion->check(
                $contentTask,
                (int) $validated['index'],
                $validated['attempt'],
                $validated,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()
            ->with($result['correct'] ? 'success' : 'error', $result['message'])
            ->with('conversion_check', $result + ['index' => (int) $validated['index']]);
    }

    public function skipConversionRow(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);

        $validated = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'skipped' => ['required', 'boolean'],
        ]);

        try {
            $this->fillBlankConversion->skip($contentTask, (int) $validated['index'], (bool) $validated['skipped']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $validated['skipped']
            ? 'Skipped — this stays MCQ only (number names / long English).'
            : 'Unskipped. Convert and Check this blank.');
    }

    public function clearConversionRows(Request $request, ContentUploadTask $contentTask): RedirectResponse
    {
        $this->authorizeTask($contentTask, $request);
        abort_unless($contentTask->isFillBlankConversion(), 404);

        $validated = $request->validate([
            'indexes' => ['required', 'array', 'min:1'],
            'indexes.*' => ['integer', 'min:0'],
        ]);

        try {
            $cleared = $this->fillBlankConversion->removeFromConversion($contentTask, $validated['indexes']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $cleared === 1
            ? 'Deleted from conversion — stays MCQ only (answer was not a number).'
            : "Deleted {$cleared} questions from conversion (MCQs kept).");
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedConversionDraft(Request $request): array
    {
        return $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            'fill_blank_question_text' => ['required', 'string', 'max:5000'],
            'fill_blank_correct_answer' => ['required', 'string', 'max:500'],
            'fill_blank_answer_format' => ['required', 'string', Rule::in([
                QuestionBlankAnswer::FORMAT_INTEGER,
                QuestionBlankAnswer::FORMAT_DECIMAL,
                QuestionBlankAnswer::FORMAT_FRACTION,
            ])],
            'fill_blank_decimal_places' => ['nullable', 'integer', 'min:1', 'max:8'],
            'include_in_written' => ['sometimes', 'boolean'],
        ]);
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
