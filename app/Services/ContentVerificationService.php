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

        $questions = $questionIds === []
            ? collect()
            : Question::query()
                ->with(['options' => fn ($query) => $query->orderBy('sort_order')])
                ->whereIn('id', $questionIds)
                ->get()
                ->sortBy(fn (Question $question) => array_search($question->id, $questionIds, true))
                ->values();

        $rows = $questions->map(function (Question $question, int $index) use ($checks, $questionMeta) {
            $check = $checks->get($question->id);
            $meta = $questionMeta[$question->id] ?? [];

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
                'checks' => $check ? [
                    'check_text' => $check->check_text,
                    'check_options' => $check->check_options,
                    'check_correct' => $check->check_correct,
                    'check_hint' => $check->check_hint,
                    'check_explanation' => $check->check_explanation,
                    'check_difficulty' => $check->check_difficulty,
                    'check_diagram' => $check->check_diagram,
                    'diagram_note' => $check->diagram_note,
                    'is_complete' => $check->isComplete(),
                ] : null,
            ];
        })->values()->all();

        $verified = collect($rows)->filter(fn ($row) => $row['is_verified'])->count();
        $total = count($rows);

        return [
            'run' => $run->fresh(),
            'questions' => $rows,
            'summary' => [
                'total' => $total,
                'verified' => $verified,
                'unverified' => $total - $verified,
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
        if ($run->user_id !== $user->id) {
            throw new \InvalidArgumentException('You cannot edit this verification run.');
        }

        if ($run->status === ContentVerificationRun::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Verification is already complete.');
        }

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

            $check->update($payloadChecks);

            $this->syncTaskStatus($run);

            return $check->fresh();
        });
    }

    public function saveCheck(
        ContentVerificationRun $run,
        int $questionId,
        array $checks,
        User $user,
    ): ContentVerificationCheck {
        if ($run->user_id !== $user->id) {
            throw new \InvalidArgumentException('You cannot edit this verification run.');
        }

        if ($run->status === ContentVerificationRun::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Verification is already complete.');
        }

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

    public function completeRun(ContentVerificationRun $run, User $user): ContentVerificationRun
    {
        if ($run->user_id !== $user->id) {
            throw new \InvalidArgumentException('You cannot complete this verification run.');
        }

        $incomplete = ContentVerificationCheck::query()
            ->where('content_verification_run_id', $run->id)
            ->get()
            ->filter(fn (ContentVerificationCheck $c) => ! $c->isComplete())
            ->count();

        if ($incomplete > 0) {
            throw new \InvalidArgumentException("{$incomplete} question(s) still need verification.");
        }

        $run->update([
            'status' => ContentVerificationRun::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);

        $run->task?->update(['status' => ContentUploadTask::STATUS_VERIFIED]);

        return $run->fresh();
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
