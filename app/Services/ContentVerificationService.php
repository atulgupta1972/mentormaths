<?php

namespace App\Services;

use App\Models\ContentQuestionCorrection;
use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ContentVerificationService
{
    public function __construct(
        private QuestionDiagramService $diagrams,
    ) {}

    /**
     * @return array{
     *     run: ContentVerificationRun,
     *     questions: list<array<string, mixed>>,
     *     summary: array{total: int, verified: int, unverified: int}
     * }
     */
    public function forTask(ContentUploadTask $task, User $user): array
    {
        $run = $this->resolveRun($task, $user);
        $questionMeta = $this->questionMetaForChapter($task);
        $questionIds = array_keys($questionMeta);

        foreach ($questionIds as $questionId) {
            ContentVerificationCheck::query()->firstOrCreate(
                [
                    'content_verification_run_id' => $run->id,
                    'question_id' => $questionId,
                ],
                ['diagram_note' => 'No diagram needed'],
            );
        }

        $checks = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->get()
            ->keyBy('question_id');

        $correctionRemarks = ContentQuestionCorrection::query()
            ->where('content_upload_task_id', $task->id)
            ->where('status', ContentQuestionCorrection::STATUS_PENDING)
            ->get()
            ->keyBy('question_id');

        $questions = $questionIds === []
            ? collect()
            : Question::query()
                ->with([
                    'blankAnswer',
                    'options' => fn ($query) => $query->orderBy('sort_order'),
                ])
                ->whereIn('id', $questionIds)
                ->get()
                ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
                ->values();

        $extractionItems = is_array($task->textbookChapter?->extraction_items)
            ? array_values($task->textbookChapter->extraction_items)
            : [];

        $rows = $questions->map(function (Question $question, int $index) use ($checks, $questionMeta, $correctionRemarks, $extractionItems) {
            $check = $checks->get($question->id);
            $meta = $questionMeta[$question->id] ?? [];
            $correction = $correctionRemarks->get($question->id);
            $sourceItem = is_array($extractionItems[$index] ?? null) ? $extractionItems[$index] : [];

            return [
                'number' => $index + 1,
                'question_id' => $question->id,
                'type' => $question->type,
                'set_code' => $meta['set_code'] ?? null,
                'set_number' => $meta['set_number'] ?? null,
                'question_text' => $question->question_text,
                'explanation' => $question->explanation,
                'method_hint' => $question->method_hint,
                'difficulty' => $question->difficulty,
                'blank_answer' => $question->blankAnswer ? [
                    'correct_answer' => $question->blankAnswer->correct_answer,
                    'answer_format' => $question->blankAnswer->answer_format,
                    'decimal_places' => $question->blankAnswer->decimal_places,
                ] : null,
                'source_options' => $this->sourceOptionsFromItem($sourceItem),
                'diagram_url' => $question->diagram_url,
                'has_diagram' => filled($question->diagram_path),
                'needs_figure' => $this->questionNeedsFigure($question),
                'correction_remark' => $correction?->remark,
                'options' => $question->options->values()->map(function (QuestionOption $option, int $optionIndex) {
                    return [
                        'id' => $option->id,
                        'letter' => chr(65 + $optionIndex),
                        'option_text' => $option->option_text,
                        'is_correct' => (bool) $option->is_correct,
                        'sort_order' => (int) $option->sort_order,
                    ];
                })->all(),
                'correct_letter' => $question->isFillInBlank()
                    ? null
                    : ($question->options
                        ->values()
                        ->search(fn (QuestionOption $option) => $option->is_correct) !== false
                        ? chr(65 + (int) $question->options->values()->search(fn (QuestionOption $option) => $option->is_correct))
                        : null),
                'correct_answer' => $question->isFillInBlank()
                    ? ($question->blankAnswer?->correct_answer)
                    : ($question->options->firstWhere('is_correct', true)?->option_text),
                'is_verified' => $check?->isComplete() ?? false,
                'is_skipped' => (bool) ($check?->skipped),
                'skip_reason' => $check?->skip_reason,
                'ai_verdict' => $check?->ai_verdict,
                'ai_confidence' => $check?->ai_confidence,
                'ai_note' => $check?->ai_note,
                'ai_reviewed_at' => $check?->ai_reviewed_at?->toIso8601String(),
                'checks' => $check ? [
                    'check_text' => $check->check_text,
                    'check_options' => $check->check_options,
                    'check_correct' => $check->check_correct,
                    'check_hint' => $check->check_hint,
                    'check_explanation' => $check->check_explanation,
                    'check_difficulty' => $check->check_difficulty,
                    'check_diagram' => $check->check_diagram,
                    'diagram_note' => $check->diagram_note,
                    'skipped' => (bool) $check->skipped,
                    'skip_reason' => $check->skip_reason,
                    'is_complete' => $check->isComplete(),
                ] : null,
            ];
        })->values()->all();

        $verified = collect($rows)->filter(fn ($row) => $row['is_verified'] && ! $row['is_skipped'])->count();
        $skipped = collect($rows)->filter(fn ($row) => $row['is_skipped'])->count();
        $total = count($rows);
        $done = collect($rows)->filter(fn ($row) => $row['is_verified'])->count();

        return [
            'run' => $run->fresh(),
            'questions' => $rows,
            'summary' => [
                'total' => $total,
                'verified' => $verified,
                'skipped' => $skipped,
                'done' => $done,
                'unverified' => $total - $done,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function questionSaveValidationRules(): array
    {
        return [
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'question_text' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'method_hint' => ['nullable', 'string', 'max:2000'],
            'difficulty' => ['nullable', 'string', 'max:64'],
            'type' => ['nullable', 'string', Rule::in([Question::TYPE_MCQ, Question::TYPE_FILL_IN_BLANK, 'fill_blank'])],
            'options' => ['nullable', 'array', 'max:8'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_text' => ['nullable', 'string', 'max:2000'],
            'options.*.is_correct' => ['nullable', 'boolean'],
            'correct_answer' => ['nullable', 'string', 'max:500'],
            'answer_format' => ['nullable', 'string', Rule::in(QuestionBlankAnswer::formats())],
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:8'],
        ];
    }

    /**
     * Save edited question content and mark it verified.
     * Supports MCQ, fill-in-blank, and switching type during verification.
     *
     * @param  array<string, mixed>  $payload
     */
    public function saveQuestion(
        ContentVerificationRun $run,
        int $questionId,
        array $payload,
        User $user,
    ): ContentVerificationCheck {
        $this->assertCanEditRun($run, $user);

        $task = $run->task;
        $allowedIds = $this->questionIdsForChapter($task);

        if (! in_array($questionId, $allowedIds, true)) {
            throw new \InvalidArgumentException('Question does not belong to this chapter task.');
        }

        $question = Question::query()->with(['options', 'blankAnswer'])->findOrFail($questionId);

        if (! $question->isMcq() && ! $question->isFillInBlank()) {
            throw new \InvalidArgumentException('Only MCQ and fill-in-blank questions can be edited here.');
        }

        $requestedType = $this->resolveRequestedType($question, $payload);

        return DB::transaction(function () use ($run, $question, $payload, $user, $requestedType) {
            if ($requestedType === Question::TYPE_FILL_IN_BLANK) {
                $this->saveFillInBlankContent($question, $payload);
            } else {
                $this->saveMcqContent($question, $payload);
            }

            $this->syncExtractionItemAfterSave($run->task, $question->fresh(['blankAnswer', 'options']), $requestedType);

            $check = ContentVerificationCheck::query()
                ->where('content_verification_run_id', $run->id)
                ->where('question_id', $question->id)
                ->firstOrFail();

            $task = $run->task;
            $queueGeminiRecheck = $task->status === ContentUploadTask::STATUS_PUBLISHED
                && in_array($check->ai_verdict, [
                    ContentAiVerificationService::VERDICT_NEEDS_FIX,
                    ContentAiVerificationService::VERDICT_NEEDS_DIAGRAM,
                ], true);

            if ($queueGeminiRecheck) {
                $recheckPayload = array_fill_keys(ContentVerificationCheck::CHECK_FIELDS, false);
                $recheckPayload['diagram_note'] = filled($question->fresh()->diagram_url)
                    ? 'Diagram reviewed'
                    : 'No diagram needed';
                $recheckPayload['verified_at'] = null;
                $recheckPayload['skipped'] = false;
                $recheckPayload['skip_reason'] = null;
                $recheckPayload['skipped_at'] = null;
                $recheckPayload['ai_verdict'] = null;
                $recheckPayload['ai_confidence'] = null;
                $recheckPayload['ai_note'] = 'Fixed manually — pending Gemini recheck';
                $recheckPayload['ai_reviewed_at'] = null;

                $check->update($recheckPayload);
            } else {
                $payloadChecks = [];
                foreach (ContentVerificationCheck::CHECK_FIELDS as $field) {
                    $payloadChecks[$field] = true;
                }
                $payloadChecks['diagram_note'] = filled($question->fresh()->diagram_url)
                    ? 'Diagram reviewed'
                    : 'No diagram needed';
                $payloadChecks['verified_at'] = now();
                $payloadChecks['skipped'] = false;
                $payloadChecks['skip_reason'] = null;
                $payloadChecks['skipped_at'] = null;

                $check->update($payloadChecks);
            }

            $this->syncTaskStatus($run);

            $fresh = $check->fresh();
            $this->maybeCompleteRunIfAllVerified($run->fresh());
            app(QuestionIssueReportService::class)->resolveAfterQuestionFixed(
                (int) $question->id,
                $user,
                'Fixed during verification — please re-attempt',
            );

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveRequestedType(Question $question, array $payload): string
    {
        $type = strtolower(trim((string) ($payload['type'] ?? $question->type)));

        if (in_array($type, [Question::TYPE_FILL_IN_BLANK, 'fill_blank'], true)) {
            return Question::TYPE_FILL_IN_BLANK;
        }

        return Question::TYPE_MCQ;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveMcqContent(Question $question, array $payload): void
    {
        $options = array_values(array_filter(
            is_array($payload['options'] ?? null) ? $payload['options'] : [],
            fn ($row) => is_array($row) && trim((string) ($row['option_text'] ?? '')) !== '',
        ));

        if (count($options) < 2) {
            throw new \InvalidArgumentException('Provide at least 2 options for an MCQ.');
        }

        $correctCount = collect($options)->filter(fn ($row) => (bool) ($row['is_correct'] ?? false))->count();
        if ($correctCount !== 1) {
            throw new \InvalidArgumentException('Mark exactly one option as the correct answer.');
        }

        $question->update([
            'type' => Question::TYPE_MCQ,
            'question_text' => trim((string) $payload['question_text']),
            'explanation' => trim((string) ($payload['explanation'] ?? '')) ?: null,
            'method_hint' => trim((string) ($payload['method_hint'] ?? '')) ?: null,
            'difficulty' => trim((string) ($payload['difficulty'] ?? '')) ?: null,
        ]);

        $existing = $question->options->keyBy('id');
        $keptIds = [];

        foreach ($options as $index => $row) {
            $optionId = isset($row['id']) ? (int) $row['id'] : 0;
            $text = trim((string) ($row['option_text'] ?? ''));
            $isCorrect = (bool) ($row['is_correct'] ?? false);

            if ($optionId > 0 && $existing->has($optionId)) {
                $existing[$optionId]->update([
                    'option_text' => $text,
                    'is_correct' => $isCorrect,
                    'sort_order' => $index + 1,
                ]);
                $keptIds[] = $optionId;
            } else {
                $created = QuestionOption::query()->create([
                    'question_id' => $question->id,
                    'option_text' => $text,
                    'is_correct' => $isCorrect,
                    'sort_order' => $index + 1,
                ]);
                $keptIds[] = $created->id;
            }
        }

        $question->options()
            ->whereNotIn('id', $keptIds)
            ->delete();

        $question->blankAnswer()->delete();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveFillInBlankContent(Question $question, array $payload): void
    {
        $answer = trim((string) ($payload['correct_answer'] ?? ''));

        if ($answer === '') {
            $answer = trim((string) ($question->blankAnswer?->correct_answer ?? ''));
        }

        if ($answer === '') {
            $answer = trim((string) ($question->options->firstWhere('is_correct', true)?->option_text ?? ''));
        }

        if ($answer === '') {
            throw new \InvalidArgumentException('Enter the fill-in-blank answer.');
        }

        $stem = trim((string) $payload['question_text']);
        if ($stem !== '' && ! str_contains($stem, '____')) {
            $stem = rtrim($stem, " \t\n\r\0\x0B.?").' The answer is ____.';
        }

        $format = trim((string) ($payload['answer_format'] ?? $question->blankAnswer?->answer_format ?? ''));
        if (! in_array($format, QuestionBlankAnswer::formats(), true)) {
            $format = app(TextbookChapterAnswerClassificationService::class)->detectAnswerFormat($answer)
                ?? QuestionBlankAnswer::FORMAT_TEXT;
        }

        $places = $payload['decimal_places'] ?? $question->blankAnswer?->decimal_places;

        $question->update([
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => $stem,
            'explanation' => trim((string) ($payload['explanation'] ?? '')) ?: null,
            'method_hint' => trim((string) ($payload['method_hint'] ?? '')) ?: null,
            'difficulty' => trim((string) ($payload['difficulty'] ?? '')) ?: null,
        ]);

        $question->blankAnswer()->updateOrCreate(
            ['question_id' => $question->id],
            [
                'answer_format' => $format,
                'correct_answer' => $format === QuestionBlankAnswer::FORMAT_INTEGER
                    ? str_replace(',', '', $answer)
                    : $answer,
                'decimal_places' => is_numeric($places) ? (int) $places : null,
            ],
        );

        $question->options()->delete();
    }

    private function syncExtractionItemAfterSave(?ContentUploadTask $task, Question $question, string $type): void
    {
        $chapter = $task?->textbookChapter;
        if (! $chapter) {
            return;
        }

        $ids = $this->questionIdsForChapter($task);
        $index = array_search($question->id, $ids, true);
        if ($index === false) {
            return;
        }

        $items = is_array($chapter->extraction_items) ? $chapter->extraction_items : [];
        if (! isset($items[$index]) || ! is_array($items[$index])) {
            return;
        }

        if ($type === Question::TYPE_FILL_IN_BLANK) {
            $items[$index]['question_type'] = 'fill_blank';
            $items[$index]['include_in_mcq'] = false;
            $items[$index]['include_in_fill_blank'] = true;
            $items[$index]['fill_blank_skipped'] = false;
            $items[$index]['fill_blank_question_text'] = $question->question_text;
            $items[$index]['fill_blank_correct_answer'] = $question->blankAnswer?->correct_answer;
            $items[$index]['fill_blank_answer_format'] = $question->blankAnswer?->answer_format;
            $items[$index]['fill_blank_decimal_places'] = $question->blankAnswer?->decimal_places;
            $items[$index]['correct_answer'] = $question->blankAnswer?->correct_answer;
        } else {
            $items[$index]['question_type'] = 'mcq';
            $items[$index]['include_in_mcq'] = true;
            $items[$index]['include_in_fill_blank'] = false;
            $items[$index]['fill_blank_skipped'] = true;
            $correct = $question->options->firstWhere('is_correct', true);
            $items[$index]['correct_answer'] = $correct?->option_text;
            $items[$index]['mcq_options'] = $question->options->values()->map(fn (QuestionOption $option) => [
                'text' => $option->option_text,
                'is_correct' => (bool) $option->is_correct,
            ])->all();
        }

        $items[$index]['question_text'] = $question->question_text;
        $items[$index]['explanation'] = $question->explanation;
        $items[$index]['method_hint'] = $question->method_hint;
        $items[$index]['difficulty'] = $question->difficulty;

        $chapter->update(['extraction_items' => $items]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array{id: null, option_text: string, is_correct: bool}>
     */
    private function sourceOptionsFromItem(array $item): array
    {
        $options = [];

        foreach (array_values($item['mcq_options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $text = trim((string) ($option['text'] ?? $option['option_text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $options[] = [
                'id' => null,
                'option_text' => $text,
                'is_correct' => (bool) ($option['is_correct'] ?? false),
            ];
        }

        return $options;
    }

    public function attachDiagram(
        ContentVerificationRun $run,
        int $questionId,
        UploadedFile $file,
        User $user,
    ): Question {
        $this->assertCanEditRun($run, $user);
        $question = $this->questionForRun($run, $questionId);
        $this->diagrams->attach($question, $file);

        return $question->fresh();
    }

    public function removeDiagram(
        ContentVerificationRun $run,
        int $questionId,
        User $user,
    ): Question {
        $this->assertCanEditRun($run, $user);
        $question = $this->questionForRun($run, $questionId);
        $this->diagrams->deleteForQuestion($question);

        return $question->fresh();
    }

    public function saveCheck(
        ContentVerificationRun $run,
        int $questionId,
        array $checks,
        User $user,
    ): ContentVerificationCheck {
        $this->assertCanEditRun($run, $user);

        $check = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $payload = [];
        foreach (ContentVerificationCheck::CHECK_FIELDS as $field) {
            $payload[$field] = (bool) ($checks[$field] ?? false);
        }
        $payload['diagram_note'] = $checks['diagram_note'] ?? $check->diagram_note ?? 'No diagram needed';
        $payload['verified_at'] = collect($payload)->only(ContentVerificationCheck::CHECK_FIELDS)->every(fn ($v) => $v)
            ? now()
            : null;

        $check->update($payload);

        $this->syncTaskStatus($run);

        return $check->fresh();
    }

    /**
     * Mark questions verified as-is (no content rewrite) — used by admin batch review.
     *
     * @param  list<int>  $questionIds
     * @return int Number of questions marked verified
     */
    public function markVerifiedBatch(ContentVerificationRun $run, array $questionIds, User $user): int
    {
        $this->assertCanEditRun($run, $user);

        $allowedIds = $this->questionIdsForChapter($run->task);
        $ids = collect($questionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && in_array($id, $allowedIds, true))
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw new \InvalidArgumentException('Select at least one question to mark verified.');
        }

        $marked = 0;

        DB::transaction(function () use ($run, $ids, &$marked) {
            $questions = Question::query()
                ->whereIn('id', $ids)
                ->get()
                ->keyBy('id');

            foreach ($ids as $questionId) {
                $check = ContentVerificationCheck::query()->firstOrCreate(
                    [
                        'content_verification_run_id' => $run->id,
                        'question_id' => $questionId,
                    ],
                    ['diagram_note' => 'No diagram needed'],
                );

                $question = $questions->get($questionId);
                $payload = array_fill_keys(ContentVerificationCheck::CHECK_FIELDS, true);
                $payload['diagram_note'] = filled($question?->diagram_path)
                    ? 'Diagram reviewed'
                    : 'No diagram needed';
                $payload['verified_at'] = now();
                $payload['skipped'] = false;
                $payload['skip_reason'] = null;
                $payload['skipped_at'] = null;
                $check->update($payload);
                $marked++;
                app(ContentUploadTaskService::class)->completeQuestionCorrection($run->task, (int) $questionId);
            }

            $this->syncTaskStatus($run);
        });

        $this->maybeCompleteRunIfAllVerified($run->fresh());

        return $marked;
    }

    /**
     * Skip an irrelevant question — counts as reviewed, excluded from uploader pay.
     */
    public function skipQuestion(
        ContentVerificationRun $run,
        int $questionId,
        User $user,
        ?string $reason = null,
    ): ContentVerificationCheck {
        $this->assertCanEditRun($run, $user);
        $this->questionForRun($run, $questionId);

        $check = ContentVerificationCheck::query()->firstOrCreate(
            [
                'content_verification_run_id' => $run->id,
                'question_id' => $questionId,
            ],
            ['diagram_note' => 'No diagram needed'],
        );

        $check->update([
            'skipped' => true,
            'skip_reason' => filled($reason) ? trim($reason) : 'Irrelevant — skipped during verification',
            'skipped_at' => now(),
            'verified_at' => now(),
        ]);

        $this->syncTaskStatus($run);
        $this->maybeCompleteRunIfAllVerified($run->fresh());

        return $check->fresh();
    }

    public function unskipQuestion(
        ContentVerificationRun $run,
        int $questionId,
        User $user,
    ): ContentVerificationCheck {
        $this->assertCanEditRun($run, $user);
        $this->questionForRun($run, $questionId);

        $check = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->where('question_id', $questionId)
            ->firstOrFail();

        $allChecked = true;
        foreach (ContentVerificationCheck::CHECK_FIELDS as $field) {
            if (! $check->{$field}) {
                $allChecked = false;
                break;
            }
        }

        $check->update([
            'skipped' => false,
            'skip_reason' => null,
            'skipped_at' => null,
            'verified_at' => $allChecked ? ($check->verified_at ?? now()) : null,
        ]);

        $this->syncTaskStatus($run);

        return $check->fresh();
    }

    /**
     * Reset verification ticks only for the given question IDs (all runs on the task).
     *
     * @param  list<int>  $questionIds
     */
    public function resetVerificationForQuestions(ContentUploadTask $task, array $questionIds): void
    {
        $ids = collect($questionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            $this->resetAllVerification($task);

            return;
        }

        $runs = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->get();

        foreach ($runs as $run) {
            $reset = array_fill_keys(ContentVerificationCheck::CHECK_FIELDS, false);
            $reset['verified_at'] = null;
            $reset['skipped'] = false;
            $reset['skip_reason'] = null;
            $reset['skipped_at'] = null;

            ContentVerificationCheck::query()
                ->where('content_verification_run_id', $run->id)
                ->whereIn('question_id', $ids)
                ->update($reset);

            if ($run->status === ContentVerificationRun::STATUS_COMPLETED) {
                $run->update([
                    'status' => ContentVerificationRun::STATUS_IN_PROGRESS,
                    'completed_at' => null,
                ]);
            }
        }
    }

    public function completeRun(ContentVerificationRun $run, User $user): ContentVerificationRun
    {
        $this->assertCanEditRun($run, $user);

        $incomplete = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->get()
            ->filter(fn (ContentVerificationCheck $c) => ! $c->isComplete())
            ->count();

        if ($incomplete > 0) {
            throw new \InvalidArgumentException("{$incomplete} question(s) still need verification.");
        }

        $this->markRunAndTaskVerified($run);

        return $run->fresh();
    }

    /**
     * When every check on the run is complete, mark run completed and task verified
     * so the list leaves "Verifying" and admin can publish.
     */
    public function maybeCompleteRunIfAllVerified(?ContentVerificationRun $run): void
    {
        if (! $run || $run->status === ContentVerificationRun::STATUS_COMPLETED) {
            return;
        }

        $task = $run->task;
        if (! $task) {
            return;
        }

        $allowedIds = $this->questionIdsForChapter($task);
        if ($allowedIds === []) {
            return;
        }

        $checks = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->whereIn('question_id', $allowedIds)
            ->get()
            ->keyBy('question_id');

        if ($checks->count() < count($allowedIds)) {
            return;
        }

        if ($checks->contains(fn (ContentVerificationCheck $check) => ! $check->isComplete())) {
            return;
        }

        $this->markRunAndTaskVerified($run);
    }

    private function markRunAndTaskVerified(ContentVerificationRun $run): void
    {
        $run->update([
            'status' => ContentVerificationRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $task = $run->task;
        if (! $task) {
            return;
        }

        if (in_array($task->status, [
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
        ], true)) {
            $task->update(['status' => ContentUploadTask::STATUS_VERIFIED]);
        }
    }

    public function resetAllVerification(ContentUploadTask $task): void
    {
        $runs = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->get();

        foreach ($runs as $run) {
            $reset = array_fill_keys(ContentVerificationCheck::CHECK_FIELDS, false);
            $reset['verified_at'] = null;
            $reset['skipped'] = false;
            $reset['skip_reason'] = null;
            $reset['skipped_at'] = null;
            $reset['ai_verdict'] = null;
            $reset['ai_confidence'] = null;
            $reset['ai_note'] = null;
            $reset['ai_reviewed_at'] = null;

            ContentVerificationCheck::query()
                ->where('content_verification_run_id', $run->id)
                ->update($reset);

            if ($run->status === ContentVerificationRun::STATUS_COMPLETED) {
                $run->update([
                    'status' => ContentVerificationRun::STATUS_IN_PROGRESS,
                    'completed_at' => null,
                ]);
            }
        }
    }

    /**
     * Clear all Gemini / verification ticks so admin can re-check a published chapter.
     */
    public function resetForGeminiReview(ContentUploadTask $task, User $user): ContentVerificationRun
    {
        if ($task->isFillBlankConversion()) {
            throw new \InvalidArgumentException('Fill-in-blank conversion tasks do not use Gemini MCQ review.');
        }

        if ($task->textbookChapter?->mcqWorksheetIds() === []) {
            throw new \InvalidArgumentException('This chapter has no MCQ worksheets to review.');
        }

        $this->resetAllVerification($task);

        return $this->resolveRun($task, $user);
    }

    /**
     * @param  iterable<ContentUploadTask>  $tasks
     * @return array<int, array<string, mixed>>
     */
    public function progressForTasks(iterable $tasks, User $user): array
    {
        $taskList = collect($tasks)->filter(fn ($task) => $task instanceof ContentUploadTask)->values();

        if ($taskList->isEmpty()) {
            return [];
        }

        $taskIds = $taskList->pluck('id')->all();

        $runs = ContentVerificationRun::query()
            ->whereIn('content_upload_task_id', $taskIds)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->get()
            ->unique('content_upload_task_id')
            ->keyBy('content_upload_task_id');

        $out = [];

        foreach ($taskList as $task) {
            $out[(int) $task->id] = $this->buildProgressSummary(
                $task,
                $runs->get($task->id),
            );
        }

        return $out;
    }

    /**
     * @return array{
     *     total: int,
     *     verified: int,
     *     pending: int,
     *     skipped: int,
     *     percent: int,
     *     sets: list<array{part: int, set_code: string, total: int, verified: int, pending: int}>,
     *     can_gemini: bool
     * }
     */
    public function progressForTask(ContentUploadTask $task, User $user): array
    {
        $run = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first();

        return $this->buildProgressSummary($task, $run);
    }

    /**
     * @return array{
     *     total: int,
     *     verified: int,
     *     pending: int,
     *     skipped: int,
     *     percent: int,
     *     sets: list<array{part: int, set_code: string, total: int, verified: int, pending: int}>,
     *     can_gemini: bool
     * }
     */
    private function buildProgressSummary(ContentUploadTask $task, ?ContentVerificationRun $run): array
    {
        $empty = [
            'total' => 0,
            'verified' => 0,
            'pending' => 0,
            'skipped' => 0,
            'percent' => 0,
            'sets' => [],
            'can_gemini' => false,
        ];

        if ($task->isFillBlankConversion()) {
            return $empty;
        }

        $questionIds = $this->questionIdsForChapter($task);
        $total = count($questionIds);

        if ($total === 0) {
            return $empty;
        }

        $canGemini = in_array($task->status, [
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true);

        $checks = $run
            ? ContentVerificationCheck::query()
                ->where('content_verification_run_id', $run->id)
                ->whereIn('question_id', $questionIds)
                ->get()
                ->keyBy('question_id')
            : collect();

        $verified = 0;
        $skipped = 0;

        foreach ($questionIds as $questionId) {
            $check = $checks->get($questionId);

            if ($this->isGeminiApproved($check)) {
                $verified++;
            } elseif ($this->isGeminiSkipped($check)) {
                $skipped++;
            }
        }

        $pending = max(0, $total - $verified - $skipped);
        $percent = $total > 0 ? (int) round(($verified / $total) * 100) : 0;

        return [
            'total' => $total,
            'verified' => $verified,
            'pending' => $pending,
            'skipped' => $skipped,
            'percent' => $percent,
            'sets' => $this->setProgressRows($task, $questionIds, $checks),
            'can_gemini' => $canGemini,
        ];
    }

    /**
     * @param  list<int>  $questionIds
     * @param  Collection<int, ContentVerificationCheck>  $checks
     * @return list<array{part: int, set_code: string, total: int, verified: int, pending: int}>
     */
    private function setProgressRows(ContentUploadTask $task, array $questionIds, $checks): array
    {
        $chapter = $task->textbookChapter;
        $setPlan = is_array($chapter?->mcq_set_plan) ? $chapter->mcq_set_plan : [];

        if ($setPlan === []) {
            return [];
        }

        $rows = [];

        foreach (collect($setPlan)->values() as $index => $part) {
            $from = max(1, (int) ($part['q_from'] ?? 1));
            $to = max($from, (int) ($part['q_to'] ?? $from));
            $sliceIds = array_slice($questionIds, $from - 1, max(0, $to - $from + 1));
            $setVerified = 0;

            foreach ($sliceIds as $questionId) {
                $check = $checks->get($questionId);

                if ($this->isGeminiApproved($check)) {
                    $setVerified++;
                }
            }

            $setTotal = count($sliceIds);

            $rows[] = [
                'part' => $index + 1,
                'set_code' => (string) ($part['set_code'] ?? ''),
                'total' => $setTotal,
                'verified' => $setVerified,
                'pending' => max(0, $setTotal - $setVerified),
            ];
        }

        return $rows;
    }

    public function isGeminiApproved(?ContentVerificationCheck $check): bool
    {
        return $check
            && $check->ai_verdict === ContentAiVerificationService::VERDICT_APPROVE;
    }

    public function isGeminiSkipped(?ContentVerificationCheck $check): bool
    {
        return $check
            && $check->ai_verdict === ContentAiVerificationService::VERDICT_SKIP;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    public function countPendingGeminiQuestions(array $questions): int
    {
        return collect($questions)
            ->filter(fn (array $row) => ! $this->isGeminiDoneRow($row))
            ->count();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function isGeminiDoneRow(array $row): bool
    {
        return in_array($row['ai_verdict'] ?? null, [
            ContentAiVerificationService::VERDICT_APPROVE,
            ContentAiVerificationService::VERDICT_SKIP,
        ], true);
    }

    private function questionForRun(ContentVerificationRun $run, int $questionId): Question
    {
        $task = $run->task;
        $allowedIds = $this->questionIdsForChapter($task);

        if (! in_array($questionId, $allowedIds, true)) {
            throw new \InvalidArgumentException('Question does not belong to this chapter task.');
        }

        $question = Question::query()->findOrFail($questionId);

        if (! $question->isMcq() && ! $question->isFillInBlank()) {
            throw new \InvalidArgumentException('Only MCQ and fill-in-blank questions support figure upload here.');
        }

        return $question;
    }

    private function questionNeedsFigure(Question $question): bool
    {
        if (filled($question->diagram_path)) {
            return false;
        }

        $haystack = strtolower(strip_tags((string) $question->question_text.' '.(string) $question->explanation));

        return str_contains($haystack, 'requires a figure upload')
            || str_contains($haystack, 'needs_diagram')
            || str_contains($haystack, 'see the figure')
            || str_contains($haystack, 'see the graph')
            || str_contains($haystack, 'see the diagram')
            || str_contains($haystack, 'shown in the figure')
            || str_contains($haystack, 'shown in the graph');
    }

    private function assertCanEditRun(ContentVerificationRun $run, User $user): void
    {
        if ($user->isAdmin()) {
            if ($run->status === ContentVerificationRun::STATUS_COMPLETED && ! $this->adminMayEditCompletedRun($run->task)) {
                throw new \InvalidArgumentException('Reopen verification or send back to the uploader before editing.');
            }

            return;
        }

        if ($run->user_id !== $user->id) {
            throw new \InvalidArgumentException('You cannot edit this verification run.');
        }

        if ($run->status !== ContentVerificationRun::STATUS_COMPLETED) {
            return;
        }

        // Uploader locked verification but still needs to fix a sum before publish.
        if ($this->uploaderMayReopenCompletedRun($run->task, $user)) {
            $this->reopenCompletedRunForUploader($run);

            return;
        }

        throw new \InvalidArgumentException('Verification is locked. Ask admin to send this chapter back if you still need to edit.');
    }

    private function uploaderMayReopenCompletedRun(?ContentUploadTask $task, User $user): bool
    {
        if (! $task || (int) $task->assigned_to_user_id !== (int) $user->id) {
            return false;
        }

        return in_array($task->status, [
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true);
    }

    private function reopenCompletedRunForUploader(ContentVerificationRun $run): void
    {
        $run->update([
            'status' => ContentVerificationRun::STATUS_IN_PROGRESS,
            'completed_at' => null,
        ]);

        $task = $run->task;
        if ($task && in_array($task->status, [
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
        ], true)) {
            $task->update([
                'status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                'submitted_at' => null,
            ]);
        }
    }

    private function adminMayEditCompletedRun(?ContentUploadTask $task): bool
    {
        if (! $task) {
            return false;
        }

        return in_array($task->status, [
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true);
    }

    private function resolveRun(ContentUploadTask $task, User $user): ContentVerificationRun
    {
        $existing = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $user->id)
            ->where('status', ContentVerificationRun::STATUS_IN_PROGRESS)
            ->first();

        if ($existing) {
            return $existing;
        }

        $completed = ContentVerificationRun::query()
            ->where('content_upload_task_id', $task->id)
            ->where('user_id', $user->id)
            ->where('status', ContentVerificationRun::STATUS_COMPLETED)
            ->latest('id')
            ->first();

        if ($completed && in_array($task->status, [
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true)) {
            return $completed;
        }

        return DB::transaction(function () use ($task, $user) {
            if (in_array($task->status, [
                ContentUploadTask::STATUS_UPLOADED,
                ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ], true)) {
                $task->update(['status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS]);
            }

            return ContentVerificationRun::create([
                'content_upload_task_id' => $task->id,
                'user_id' => $user->id,
                'status' => ContentVerificationRun::STATUS_IN_PROGRESS,
                'started_at' => now(),
            ]);
        });
    }

    private function syncTaskStatus(ContentVerificationRun $run): void
    {
        $task = $run->task;

        if ($task && $task->status === ContentUploadTask::STATUS_UPLOADED) {
            $task->update(['status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS]);
        }
    }

    /**
     * @return array<int, array{set_code: ?string, set_number: ?int}>
     */
    private function questionMetaForChapter(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;

        if (! $chapter) {
            return [];
        }

        $meta = [];

        foreach ($chapter->mcqWorksheetIds() as $worksheetId) {
            $worksheet = Worksheet::query()
                ->with(['questions' => fn ($q) => $q->orderByPivot('sort_order')])
                ->find($worksheetId);

            if (! $worksheet) {
                continue;
            }

            foreach ($worksheet->questions as $question) {
                $meta[$question->id] = [
                    'set_code' => $worksheet->set_code,
                    'set_number' => $worksheet->set_number,
                ];
            }
        }

        return $meta;
    }

    /**
     * @return list<int>
     */
    private function questionIdsForChapter(ContentUploadTask $task): array
    {
        return array_map('intval', array_keys($this->questionMetaForChapter($task)));
    }
}
