<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\Worksheet;
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

        $correctionRemarks = \App\Models\ContentQuestionCorrection::query()
            ->where('content_upload_task_id', $task->id)
            ->where('status', \App\Models\ContentQuestionCorrection::STATUS_PENDING)
            ->get()
            ->keyBy('question_id');

        $questions = $questionIds === []
            ? collect()
            : Question::query()
                ->with([
                    'options' => fn ($query) => $query->orderBy('sort_order'),
                    'blankAnswer',
                ])
                ->whereIn('id', $questionIds)
                ->get()
                ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
                ->values();

        $rows = $questions->map(function (Question $question, int $index) use ($checks, $questionMeta, $correctionRemarks) {
            $check = $checks->get($question->id);
            $meta = $questionMeta[$question->id] ?? [];
            $correction = $correctionRemarks->get($question->id);
            $isFillInBlank = $question->isFillInBlank();

            return [
                'number' => $index + 1,
                'question_id' => $question->id,
                'question_type' => $question->type,
                'is_fill_in_blank' => $isFillInBlank,
                'set_code' => $meta['set_code'] ?? null,
                'set_number' => $meta['set_number'] ?? null,
                'question_text' => $question->question_text,
                'explanation' => $question->explanation,
                'method_hint' => $question->method_hint,
                'difficulty' => $question->difficulty,
                'correct_answer' => $question->blankAnswer?->correct_answer,
                'answer_format' => $question->blankAnswer?->answer_format,
                'decimal_places' => $question->blankAnswer?->decimal_places,
                'diagram_url' => $question->diagram_url,
                'has_diagram' => filled($question->diagram_path),
                'needs_figure' => $this->questionNeedsFigure($question),
                'correction_remark' => $correction?->remark,
                'options' => $isFillInBlank ? [] : $question->options->values()->map(function (QuestionOption $option, int $optionIndex) {
                    return [
                        'id' => $option->id,
                        'letter' => chr(65 + $optionIndex),
                        'option_text' => $option->option_text,
                        'is_correct' => (bool) $option->is_correct,
                        'sort_order' => (int) $option->sort_order,
                    ];
                })->all(),
                'correct_letter' => $isFillInBlank ? null : (
                    $question->options
                        ->values()
                        ->search(fn (QuestionOption $option) => $option->is_correct) !== false
                        ? chr(65 + (int) $question->options->values()->search(fn (QuestionOption $option) => $option->is_correct))
                        : null
                ),
                'is_verified' => $check?->isCompleteFor($question) ?? false,
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
                    'is_complete' => $check->isCompleteFor($question),
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
    public static function validationRulesForQuestion(Question $question): array
    {
        $base = [
            'run_id' => ['required', 'integer', 'exists:content_verification_runs,id'],
            'question_id' => ['required', 'integer', 'exists:questions,id'],
            'question_text' => ['required', 'string', 'max:5000'],
            'explanation' => ['nullable', 'string', 'max:5000'],
            'method_hint' => ['nullable', 'string', 'max:2000'],
            'difficulty' => ['nullable', 'string', 'max:64'],
        ];

        if ($question->isFillInBlank()) {
            return array_merge($base, [
                'correct_answer' => ['required', 'string', 'max:500'],
                'answer_format' => ['required', 'string', Rule::in(QuestionBlankAnswer::formats())],
                'decimal_places' => ['nullable', 'integer', 'min:0', 'max:6'],
            ]);
        }

        return array_merge($base, [
            'options' => ['required', 'array', 'min:2', 'max:8'],
            'options.*.id' => ['nullable', 'integer'],
            'options.*.option_text' => ['required', 'string', 'max:2000'],
            'options.*.is_correct' => ['required', 'boolean'],
        ]);
    }

    /**
     * Save edited question content and mark it verified.
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

        $question = $this->questionForRun($run, $questionId);

        if ($question->isFillInBlank()) {
            return $this->saveFillBlankQuestion($run, $question, $payload, $user);
        }

        return $this->saveMcqQuestion($run, $question, $payload, $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveMcqQuestion(
        ContentVerificationRun $run,
        Question $question,
        array $payload,
        User $user,
    ): ContentVerificationCheck {
        $options = $payload['options'] ?? [];
        if (! is_array($options) || count($options) < 2) {
            throw new \InvalidArgumentException('Provide at least 2 options.');
        }

        $correctCount = collect($options)->filter(fn ($row) => (bool) ($row['is_correct'] ?? false))->count();
        if ($correctCount !== 1) {
            throw new \InvalidArgumentException('Mark exactly one option as the correct answer.');
        }

        return DB::transaction(function () use ($run, $question, $payload, $options, $user) {
            $question->update([
                'question_text' => trim((string) $payload['question_text']),
                'explanation' => trim((string) ($payload['explanation'] ?? '')) ?: null,
                'method_hint' => trim((string) ($payload['method_hint'] ?? '')) ?: null,
                'difficulty' => trim((string) ($payload['difficulty'] ?? '')) ?: null,
            ]);

            $existing = $question->options->keyBy('id');
            $keptIds = [];

            foreach (array_values($options) as $index => $row) {
                $optionId = isset($row['id']) ? (int) $row['id'] : 0;
                $text = trim((string) ($row['option_text'] ?? ''));
                $isCorrect = (bool) ($row['is_correct'] ?? false);

                if ($text === '') {
                    throw new \InvalidArgumentException('Option text cannot be empty.');
                }

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

            return $this->finalizeVerifiedQuestion($run, $question, $user);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function saveFillBlankQuestion(
        ContentVerificationRun $run,
        Question $question,
        array $payload,
        User $user,
    ): ContentVerificationCheck {
        $correctAnswer = trim((string) ($payload['correct_answer'] ?? ''));
        if ($correctAnswer === '') {
            throw new \InvalidArgumentException('Provide the correct answer.');
        }

        $answerFormat = (string) ($payload['answer_format'] ?? QuestionBlankAnswer::FORMAT_INTEGER);
        if (! in_array($answerFormat, QuestionBlankAnswer::formats(), true)) {
            throw new \InvalidArgumentException('Invalid answer format.');
        }

        return DB::transaction(function () use ($run, $question, $payload, $user, $correctAnswer, $answerFormat) {
            $question->update([
                'question_text' => trim((string) $payload['question_text']),
                'explanation' => trim((string) ($payload['explanation'] ?? '')) ?: null,
                'method_hint' => trim((string) ($payload['method_hint'] ?? '')) ?: null,
                'difficulty' => trim((string) ($payload['difficulty'] ?? '')) ?: null,
            ]);

            $question->blankAnswer()->updateOrCreate(
                ['question_id' => $question->id],
                [
                    'answer_format' => $answerFormat,
                    'correct_answer' => $correctAnswer,
                    'decimal_places' => $answerFormat === QuestionBlankAnswer::FORMAT_DECIMAL
                        ? ($payload['decimal_places'] ?? null)
                        : null,
                ],
            );

            return $this->finalizeVerifiedQuestion($run, $question->fresh(['blankAnswer']), $user);
        });
    }

    private function finalizeVerifiedQuestion(
        ContentVerificationRun $run,
        Question $question,
        User $user,
    ): ContentVerificationCheck {
        $check = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->where('question_id', $question->id)
            ->firstOrFail();

        $task = $run->task;
        $isFillInBlank = $question->isFillInBlank();
        // Fill-in-blanks: human verify after Gemini clears the Gemini gate (no second paste).
        // MCQs on published tasks still queue a Gemini recheck when AI flagged them.
        $queueGeminiRecheck = ! $isFillInBlank
            && $task->status === ContentUploadTask::STATUS_PUBLISHED
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
            $payloadChecks = array_fill_keys(ContentVerificationCheck::CHECK_FIELDS, false);
            foreach (ContentVerificationCheck::requiredFieldsFor($question) as $field) {
                $payloadChecks[$field] = true;
            }
            $payloadChecks['diagram_note'] = filled($question->fresh()->diagram_url)
                ? 'Diagram reviewed'
                : 'No diagram needed';
            $payloadChecks['verified_at'] = now();
            $payloadChecks['skipped'] = false;
            $payloadChecks['skip_reason'] = null;
            $payloadChecks['skipped_at'] = null;

            // Clear Gemini pending when human finishes a flagged row (or any fill-in-blank).
            $flaggedByGemini = in_array($check->ai_verdict, [
                ContentAiVerificationService::VERDICT_NEEDS_FIX,
                ContentAiVerificationService::VERDICT_NEEDS_DIAGRAM,
            ], true);
            if ($isFillInBlank || $flaggedByGemini) {
                $alreadyApproved = $check->ai_verdict === ContentAiVerificationService::VERDICT_APPROVE;
                $payloadChecks['ai_verdict'] = ContentAiVerificationService::VERDICT_APPROVE;
                $payloadChecks['ai_confidence'] = $check->ai_confidence ?: 'high';
                $payloadChecks['ai_note'] = $alreadyApproved && filled($check->ai_note)
                    ? $check->ai_note
                    : (filled($check->ai_note)
                        ? 'Fixed after Gemini — verified'
                        : ($isFillInBlank ? 'Fill-in-blank verified' : 'Verified after Gemini flag'));
                $payloadChecks['ai_reviewed_at'] = $check->ai_reviewed_at ?? now();
            }

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
    }

    public function attachDiagram(
        ContentVerificationRun $run,
        int $questionId,
        \Illuminate\Http\UploadedFile $file,
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
                $payload = array_fill_keys(ContentVerificationCheck::CHECK_FIELDS, false);
                foreach (ContentVerificationCheck::requiredFieldsFor($question ?? new Question(['type' => Question::TYPE_MCQ])) as $field) {
                    $payload[$field] = true;
                }
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
            // Skipped sums must clear the Gemini publish gate (same as Status: Skip).
            'ai_verdict' => ContentAiVerificationService::VERDICT_SKIP,
            'ai_confidence' => $check->ai_confidence ?: 'high',
            'ai_note' => filled($check->ai_note)
                ? $check->ai_note
                : 'Skipped during verification',
            'ai_reviewed_at' => $check->ai_reviewed_at ?? now(),
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

        $clearSkipVerdict = $check->ai_verdict === ContentAiVerificationService::VERDICT_SKIP;

        $check->update([
            'skipped' => false,
            'skip_reason' => null,
            'skipped_at' => null,
            'verified_at' => $allChecked ? ($check->verified_at ?? now()) : null,
            'ai_verdict' => $clearSkipVerdict ? null : $check->ai_verdict,
            'ai_note' => $clearSkipVerdict ? null : $check->ai_note,
            'ai_confidence' => $clearSkipVerdict ? null : $check->ai_confidence,
            'ai_reviewed_at' => $clearSkipVerdict ? null : $check->ai_reviewed_at,
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
            ->get();

        $questions = Question::query()
            ->whereIn('id', $incomplete->pluck('question_id'))
            ->get()
            ->keyBy('id');

        $incompleteCount = $incomplete
            ->filter(fn (ContentVerificationCheck $c) => ! $c->isCompleteFor($questions->get($c->question_id) ?? new Question(['type' => Question::TYPE_MCQ])))
            ->count();

        if ($incompleteCount > 0) {
            throw new \InvalidArgumentException("{$incompleteCount} question(s) still need verification.");
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

        $questions = Question::query()
            ->whereIn('id', $allowedIds)
            ->get()
            ->keyBy('id');

        if ($checks->contains(fn (ContentVerificationCheck $check) => ! $check->isCompleteFor(
            $questions->get($check->question_id) ?? new Question(['type' => Question::TYPE_MCQ]),
        ))) {
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

            if ($this->isGeminiSkipped($check)) {
                $skipped++;
            } elseif ($this->isGeminiApproved($check) || $this->isGeminiClearedByHumanFix($check)) {
                $verified++;
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
     * @param  \Illuminate\Support\Collection<int, ContentVerificationCheck>  $checks
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

                if ($this->isGeminiApproved($check) || $this->isGeminiClearedByHumanFix($check)) {
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
        if (! $check) {
            return false;
        }

        // Manual Skip (Not paid) must count even if older rows lack ai_verdict=skip.
        return $check->skipped
            || $check->ai_verdict === ContentAiVerificationService::VERDICT_SKIP;
    }

    /**
     * Human saved after Gemini flagged Needs Verification / Needs Diagram.
     */
    public function isGeminiClearedByHumanFix(?ContentVerificationCheck $check): bool
    {
        return $check
            && $check->verified_at !== null
            && ! $check->skipped
            && in_array($check->ai_verdict, [
                ContentAiVerificationService::VERDICT_NEEDS_FIX,
                ContentAiVerificationService::VERDICT_NEEDS_DIAGRAM,
            ], true);
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
        if (! empty($row['is_skipped'])) {
            return true;
        }

        $verdict = $row['ai_verdict'] ?? null;

        if (in_array($verdict, [
            ContentAiVerificationService::VERDICT_APPROVE,
            ContentAiVerificationService::VERDICT_SKIP,
        ], true)) {
            return true;
        }

        // Already human-verified after a Gemini flag — do not block submit.
        return ! empty($row['is_verified'])
            && in_array($verdict, [
                ContentAiVerificationService::VERDICT_NEEDS_FIX,
                ContentAiVerificationService::VERDICT_NEEDS_DIAGRAM,
            ], true);
    }

    private function questionForRun(ContentVerificationRun $run, int $questionId): Question
    {
        $task = $run->task;
        $allowedIds = $this->questionIdsForChapter($task);

        if (! in_array($questionId, $allowedIds, true)) {
            throw new \InvalidArgumentException('Question does not belong to this chapter task.');
        }

        return Question::query()->with(['options', 'blankAnswer'])->findOrFail($questionId);
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
