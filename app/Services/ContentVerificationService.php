<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\Question;
use App\Models\User;
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
        $questionIds = $this->questionIdsForChapter($task);

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
            ->with('question:id,question_text,difficulty')
            ->get()
            ->keyBy('question_id');

        $questions = Question::query()
            ->whereIn('id', $questionIds)
            ->orderByRaw('FIELD(id, '.implode(',', $questionIds ?: [0]).')')
            ->get(['id', 'question_text', 'difficulty']);

        $rows = $questions->map(function (Question $question) use ($checks) {
            $check = $checks->get($question->id);

            return [
                'question_id' => $question->id,
                'question_text' => $question->question_text,
                'difficulty' => $question->difficulty,
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

        $verified = collect($rows)->filter(fn ($row) => $row['checks']['is_complete'] ?? false)->count();
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
     * @return list<int>
     */
    private function questionIdsForChapter(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;
        $chapter->loadMissing(['mcqWorksheet.questions', 'textbook']);

        $ids = [];

        foreach ($chapter->mcqWorksheetIds() as $worksheetId) {
            $worksheet = \App\Models\Worksheet::query()->with(['questions' => fn ($q) => $q->orderByPivot('sort_order')])->find($worksheetId);
            if ($worksheet) {
                foreach ($worksheet->questions as $question) {
                    $ids[] = $question->id;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
