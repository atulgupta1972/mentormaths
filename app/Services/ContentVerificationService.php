<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Support\Facades\DB;

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
                ->with(['options' => fn ($query) => $query->orderBy('sort_order')])
                ->whereIn('id', $questionIds)
                ->get()
                ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
                ->values();

        $rows = $questions->map(function (Question $question, int $index) use ($checks, $questionMeta, $correctionRemarks) {
            $check = $checks->get($question->id);
            $meta = $questionMeta[$question->id] ?? [];
            $correction = $correctionRemarks->get($question->id);

            return [
                'number' => $index + 1,
                'question_id' => $question->id,
                'set_code' => $meta['set_code'] ?? null,
                'set_number' => $meta['set_number'] ?? null,
                'question_text' => $question->question_text,
                'explanation' => $question->explanation,
                'method_hint' => $question->method_hint,
                'difficulty' => $question->difficulty,
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
                'correct_letter' => $question->options
                    ->values()
                    ->search(fn (QuestionOption $option) => $option->is_correct) !== false
                    ? chr(65 + (int) $question->options->values()->search(fn (QuestionOption $option) => $option->is_correct))
                    : null,
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

        $task = $run->task;
        $allowedIds = $this->questionIdsForChapter($task);

        if (! in_array($questionId, $allowedIds, true)) {
            throw new \InvalidArgumentException('Question does not belong to this chapter task.');
        }

        $question = Question::query()->with('options')->findOrFail($questionId);

        if (! $question->isMcq()) {
            throw new \InvalidArgumentException('Only MCQ questions can be edited here.');
        }

        $options = $payload['options'] ?? [];
        if (! is_array($options) || count($options) < 2) {
            throw new \InvalidArgumentException('Provide at least 2 options.');
        }

        $correctCount = collect($options)->filter(fn ($row) => (bool) ($row['is_correct'] ?? false))->count();
        if ($correctCount !== 1) {
            throw new \InvalidArgumentException('Mark exactly one option as the correct answer.');
        }

        return DB::transaction(function () use ($run, $question, $payload, $options) {
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

            $check = ContentVerificationCheck::query()
                ->where('content_verification_run_id', $run->id)
                ->where('question_id', $question->id)
                ->firstOrFail();

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

            $this->syncTaskStatus($run);

            $fresh = $check->fresh();
            $this->maybeCompleteRunIfAllVerified($run->fresh());
            app(ContentUploadTaskService::class)->completeQuestionCorrection($run->task, (int) $question->id);

            return $fresh;
        });
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

    private function questionForRun(ContentVerificationRun $run, int $questionId): Question
    {
        $task = $run->task;
        $allowedIds = $this->questionIdsForChapter($task);

        if (! in_array($questionId, $allowedIds, true)) {
            throw new \InvalidArgumentException('Question does not belong to this chapter task.');
        }

        $question = Question::query()->findOrFail($questionId);

        if (! $question->isMcq()) {
            throw new \InvalidArgumentException('Only MCQ questions support figure upload here.');
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

        if ($run->status === ContentVerificationRun::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Verification is already complete.');
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
