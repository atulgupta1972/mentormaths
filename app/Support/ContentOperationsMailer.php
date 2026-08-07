<?php

namespace App\Support;

use App\Mail\ContentTaskAgreementAdmin;
use App\Mail\ContentTaskAssignedUploader;
use App\Mail\ContentTaskSubmittedForPublishAdmin;
use App\Models\ContentUploadTask;
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
}
