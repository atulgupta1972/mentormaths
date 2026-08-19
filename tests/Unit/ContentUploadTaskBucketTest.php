<?php

namespace Tests\Unit;

use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use Tests\TestCase;

class ContentUploadTaskBucketTest extends TestCase
{
    public function test_uploader_bucket_upload_pending_when_in_progress_without_sets(): void
    {
        $task = new ContentUploadTask(['status' => ContentUploadTask::STATUS_IN_PROGRESS]);
        $chapter = new TextbookChapter(['mcq_worksheet_id' => null, 'mcq_worksheet_ids' => null]);
        $task->setRelation('textbookChapter', $chapter);

        $this->assertSame('upload_pending', $task->uploaderBucket());
    }

    public function test_uploader_bucket_review_pending_when_sets_saved(): void
    {
        $task = new ContentUploadTask(['status' => ContentUploadTask::STATUS_IN_PROGRESS]);
        $chapter = new TextbookChapter(['mcq_worksheet_id' => 99, 'mcq_worksheet_ids' => null]);
        $task->setRelation('textbookChapter', $chapter);

        $this->assertSame('review_pending', $task->uploaderBucket());
    }

    public function test_conversion_task_buckets_to_convert_pending_after_agree(): void
    {
        $task = new ContentUploadTask([
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
        ]);

        $this->assertSame('convert_pending', $task->uploaderBucket());
        $this->assertSame('Fill-in-blank conversion', $task->uploaderBucketLabel());
    }
}
