<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use App\Models\User;

class ContentTextbookAccessService
{
    public function canAccessChapter(User $user, TextbookChapter $chapter): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isContentUploader()) {
            return false;
        }

        return ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('assigned_to_user_id', $user->id)
            ->whereIn('status', [
                ContentUploadTask::STATUS_IN_PROGRESS,
                ContentUploadTask::STATUS_UPLOADED,
                ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                ContentUploadTask::STATUS_VERIFIED,
            ])
            ->exists();
    }

    public function authorizeChapter(User $user, TextbookChapter $chapter): void
    {
        if (! $this->canAccessChapter($user, $chapter)) {
            abort(403, 'You are not assigned to this chapter.');
        }
    }
}
