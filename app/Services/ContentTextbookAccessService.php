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

        return $this->assignedTask($user, $chapter) !== null;
    }

    public function assignedTask(User $user, TextbookChapter $chapter): ?ContentUploadTask
    {
        return ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->latest('id')
            ->first();
    }

    public function authorizeChapter(User $user, TextbookChapter $chapter): void
    {
        if (! $this->canAccessChapter($user, $chapter)) {
            abort(403, 'You are not assigned to this chapter.');
        }
    }
}
