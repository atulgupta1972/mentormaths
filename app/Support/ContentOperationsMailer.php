<?php

namespace App\Support;

use App\Mail\ContentTaskGeminiPendingUploader;
use App\Mail\ContentTaskAgreementAdmin;
use App\Mail\ContentTaskAssignedUploader;
use App\Mail\ContentTaskReturnedUploader;
use App\Mail\ContentTaskSubmittedForPublishAdmin;
use App\Mail\ContentUploaderBatchPaymentMail;
use App\Mail\ContentUploaderPaymentMail;
use App\Models\ContentUploadTask;
use App\Models\ContentUploaderPayment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ContentOperationsMailer
{
    /**
     * @param  list<ContentUploadTask>|Collection<int, ContentUploadTask>  $tasks
     */
    public static function notifyAssigned(User $uploader, array|Collection $tasks): bool
    {
        $tasks = collect($tasks);

        if ($tasks->isEmpty() || ! str_contains($uploader->email, '@')) {
            return false;
        }

        $taskIds = $tasks->pluck('id')->filter()->all();

        $loadedTasks = ContentUploadTask::query()
            ->whereIn('id', $taskIds)
            ->with([
                'textbookChapter.textbook.gradeLevel',
                'textbookChapter.syllabusChapter:id,name,chapter_number',
            ])
            ->get();

        try {
            Mail::to($uploader->email)->send(new ContentTaskAssignedUploader($uploader, $loadedTasks));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send content task assigned uploader email.', [
                'uploader_id' => $uploader->id,
                'task_ids' => $tasks->pluck('id')->all(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function notifyAgreement(ContentUploadTask $task): void
    {
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->send(new ContentTaskAgreementAdmin($task));
        } catch (\Throwable $e) {
            Log::error('Failed to send content task agreement admin email.', [
                'content_upload_task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Email uploader reminder when Gemini MCQ review is pending.
     *
     * @param  array<int|string, mixed>|Collection<int, mixed>  $tasks  Serialized task arrays or objects with an `id`.
     */
    public static function notifyGeminiPendingUploader(User $uploader, array|\Illuminate\Support\Collection $tasks): bool
    {
        $tasks = collect($tasks);

        if ($tasks->isEmpty() || ! str_contains((string) $uploader->email, '@')) {
            return false;
        }

        $taskIds = $tasks
            ->map(fn ($task) => is_array($task) ? ($task['id'] ?? null) : ($task->id ?? null))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        if ($taskIds === []) {
            return false;
        }

        $loadedTasks = ContentUploadTask::query()
            ->whereIn('id', $taskIds)
            ->with([
                'assignee',
                'textbookChapter.textbook.gradeLevel',
                'textbookChapter.syllabusChapter:id,name,chapter_number',
            ])
            ->get();

        if ($loadedTasks->isEmpty()) {
            return false;
        }

        try {
            Mail::to($uploader->email)->send(new ContentTaskGeminiPendingUploader($uploader, $loadedTasks));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send Gemini pending reminder email.', [
                'uploader_id' => $uploader->id,
                'task_ids' => $taskIds,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function notifySubmittedForPublish(ContentUploadTask $task): void
    {
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->send(new ContentTaskSubmittedForPublishAdmin($task));
        } catch (\Throwable $e) {
            Log::error('Failed to send content task submitted admin email.', [
                'content_upload_task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  list<array{question_id: int, remark?: string, number?: int|null, question_text?: ?string}>  $items
     */
    public static function notifyReturnedForReverification(ContentUploadTask $task, array $items = []): bool
    {
        $uploader = $task->assignee;

        if (! $uploader || ! str_contains($uploader->email, '@')) {
            return false;
        }

        try {
            Mail::to($uploader->email)->send(new ContentTaskReturnedUploader($task, $items));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send content task returned uploader email.', [
                'content_upload_task_id' => $task->id,
                'uploader_id' => $uploader->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function notifyBatchPaymentRecorded(\Illuminate\Support\Collection $payments): bool
    {
        $first = $payments->first();
        $uploader = $first?->task?->assignee;

        if (! $uploader || ! str_contains($uploader->email, '@')) {
            return false;
        }

        try {
            if ($payments->count() === 1) {
                Mail::to($uploader->email)->send(new ContentUploaderPaymentMail($payments->first()));
            } else {
                Mail::to($uploader->email)->send(new ContentUploaderBatchPaymentMail($uploader, $payments));
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send content uploader batch payment email.', [
                'payment_ids' => $payments->pluck('id')->all(),
                'uploader_id' => $uploader->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public static function notifyPaymentRecorded(ContentUploaderPayment $payment): bool
    {
        return self::notifyBatchPaymentRecorded(collect([$payment]));
    }
}
