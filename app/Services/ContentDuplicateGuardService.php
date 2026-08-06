<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;

class ContentDuplicateGuardService
{
    /**
     * @return array{blocked: bool, reason: ?string}
     */
    public function check(TextbookChapter $chapter): array
    {
        if ($chapter->status === TextbookChapter::STATUS_PUBLISHED) {
            return [
                'blocked' => true,
                'reason' => 'This chapter is already published.',
            ];
        }

        $existingTask = ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->first();

        if ($existingTask) {
            return [
                'blocked' => true,
                'reason' => 'Another uploader is already assigned to this chapter.',
            ];
        }

        if ($chapter->mcqWorksheetIds() !== []) {
            return [
                'blocked' => true,
                'reason' => 'This chapter already has MCQ content imported.',
            ];
        }

        return ['blocked' => false, 'reason' => null];
    }
}
