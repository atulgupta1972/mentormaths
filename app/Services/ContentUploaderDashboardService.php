<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\User;
use Illuminate\Support\Collection;

class ContentUploaderDashboardService
{
    /**
     * @return array{
     *     tasks: Collection<int, array<string, mixed>>,
     *     summary: array{upload_pending: int, review_pending: int, total_active: int},
     *     uploadPending: Collection<int, array<string, mixed>>,
     *     reviewPending: Collection<int, array<string, mixed>>
     * }
     */
    public function forUser(User $user): array
    {
        $tasks = ContentUploadTask::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with(['textbookChapter.textbook.gradeLevel'])
            ->latest()
            ->get()
            ->map(fn (ContentUploadTask $task) => $this->serializeTask($task));

        $uploadPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'upload_pending')->values();
        $reviewPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'review_pending')->values();

        return [
            'tasks' => $tasks,
            'summary' => [
                'upload_pending' => $uploadPending->count(),
                'review_pending' => $reviewPending->count(),
                'total_active' => $uploadPending->count() + $reviewPending->count(),
            ],
            'uploadPending' => $uploadPending,
            'reviewPending' => $reviewPending,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTask(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;

        return [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'agreed_at' => $task->agreed_at?->toIso8601String(),
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'bucket' => $task->uploaderBucket(),
            'bucket_label' => $task->uploaderBucketLabel(),
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'textbook_name' => $chapter->textbook?->name,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
            ] : null,
        ];
    }
}
