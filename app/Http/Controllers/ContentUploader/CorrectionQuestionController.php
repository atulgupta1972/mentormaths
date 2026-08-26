<?php

namespace App\Http\Controllers\ContentUploader;

use App\Http\Controllers\Controller;
use App\Models\ContentQuestionCorrection;
use App\Models\Question;
use App\Services\ContentUploadTaskService;
use App\Services\McqImportService;
use App\Services\QuestionDiagramService;
use App\Services\QuestionIssueReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CorrectionQuestionController extends Controller
{
    public function __construct(
        private ContentUploadTaskService $taskService,
        private McqImportService $importService,
        private QuestionIssueReportService $issueReports,
    ) {}

    public function edit(Request $request, ContentQuestionCorrection $correction): Response|RedirectResponse
    {
        $correction->loadMissing(['task', 'question.options', 'question.blankAnswer', 'question.topic']);
        abort_unless($correction->task, 404);
        $this->authorizeAssignee($correction, $request);

        if (! $correction->isPending()) {
            return redirect()
                ->route('content.tasks.index')
                ->with('success', 'This sum was already corrected.');
        }

        try {
            $this->taskService->startQuestionCorrection($correction, $request->user());
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('content.tasks.index')
                ->with('error', $e->getMessage());
        }

        $question = $correction->question;
        abort_unless($question, 404);

        return Inertia::render('ContentUploader/Corrections/Edit', [
            'correction' => [
                'id' => $correction->id,
                'remark' => $correction->remark,
                'question_number' => $correction->question_number,
                'task_id' => $correction->content_upload_task_id,
            ],
            'question' => [
                'id' => $question->id,
                'type' => $question->type,
                'is_mcq' => $question->isMcq(),
                'is_fill_in_blank' => $question->isFillInBlank(),
                'question_text' => $question->question_text,
                'explanation' => $question->explanation,
                'method_hint' => $question->method_hint,
                'difficulty' => $question->difficulty,
                'diagram_url' => $question->diagram_url,
                'options' => $question->options->values()->map(fn ($opt) => [
                    'id' => $opt->id,
                    'option_text' => $opt->option_text,
                    'is_correct' => (bool) $opt->is_correct,
                ])->all(),
                'blank_answer' => $question->blankAnswer ? [
                    'answer_format' => $question->blankAnswer->answer_format,
                    'correct_answer' => $question->blankAnswer->correct_answer,
                    'decimal_places' => $question->blankAnswer->decimal_places,
                ] : null,
            ],
        ]);
    }

    public function update(Request $request, ContentQuestionCorrection $correction): RedirectResponse
    {
        $correction->loadMissing(['task', 'question']);
        abort_unless($correction->task && $correction->question, 404);
        $this->authorizeAssignee($correction, $request);

        if (! $correction->isPending()) {
            return redirect()
                ->route('content.tasks.index')
                ->with('error', 'This sum was already corrected.');
        }

        $question = $correction->question;

        try {
            if ($question->isFillInBlank()) {
                $this->updateFillBlank($request, $question);
            } elseif ($question->isMcq()) {
                $this->updateMcq($request, $question);
            } else {
                throw new \InvalidArgumentException('This question type cannot be edited here. Ask admin to fix it.');
            }

            $this->taskService->completeQuestionCorrection($correction->task, (int) $question->id);
            $returned = $this->issueReports->markFixedForQuestion(
                (int) $question->id,
                $request->user(),
                'Fixed by content uploader — please re-attempt',
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = 'Sum updated and returned to the student'
            .($returned > 1 ? "s ({$returned} reports)." : '.');

        return redirect()
            ->route('content.tasks.index')
            ->with('success', $message);
    }

    private function updateMcq(Request $request, Question $question): void
    {
        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'explanation' => ['nullable', 'string'],
            'method_hint' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_text' => ['required', 'string'],
            'options.*.is_correct' => ['boolean'],
            'diagram' => ['nullable', 'image', 'max:5120'],
            'remove_diagram' => ['nullable', 'boolean'],
        ]);

        $this->importService->syncQuestion($question, $validated);

        $diagramService = app(QuestionDiagramService::class);

        if ($request->boolean('remove_diagram')) {
            $diagramService->deleteForQuestion($question);
        } elseif ($request->hasFile('diagram')) {
            $diagramService->attach($question, $request->file('diagram'));
        }
    }

    private function updateFillBlank(Request $request, Question $question): void
    {
        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'answer_format' => ['required', 'in:integer,decimal,fraction,text'],
            'correct_answer' => ['required', 'string', 'max:64'],
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'],
            'explanation' => ['nullable', 'string'],
            'method_hint' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'diagram' => ['nullable', 'image', 'max:5120'],
            'remove_diagram' => ['nullable', 'boolean'],
        ]);

        $question->update([
            'question_text' => $validated['question_text'],
            'explanation' => $validated['explanation'] ?? null,
            'method_hint' => $validated['method_hint'] ?? null,
            'difficulty' => $validated['difficulty'] ?? null,
        ]);

        $question->blankAnswer()->updateOrCreate(
            ['question_id' => $question->id],
            [
                'answer_format' => $validated['answer_format'],
                'correct_answer' => trim($validated['correct_answer']),
                'decimal_places' => $validated['decimal_places'] ?? null,
            ],
        );

        $diagramService = app(QuestionDiagramService::class);

        if ($request->boolean('remove_diagram')) {
            $diagramService->deleteForQuestion($question);
        } elseif ($request->hasFile('diagram')) {
            $diagramService->attach($question, $request->file('diagram'));
        }
    }

    private function authorizeAssignee(ContentQuestionCorrection $correction, Request $request): void
    {
        abort_unless(
            (int) $correction->task?->assigned_to_user_id === (int) $request->user()->id,
            403,
        );
    }
}
