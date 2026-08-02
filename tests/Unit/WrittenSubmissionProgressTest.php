<?php

namespace Tests\Unit;

use App\Models\WrittenSubmission;
use App\Support\WrittenSubmissionProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WrittenSubmissionProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_checking_minutes_is_rounded_integer(): void
    {
        $submission = WrittenSubmission::query()->make([
            'uploaded_at' => now()->subMinutes(283),
            'status' => WrittenSubmission::STATUS_UPLOADED,
        ]);

        $this->assertSame(283, WrittenSubmissionProgress::checkingMinutes($submission));
    }

    public function test_queued_submission_reports_low_progress(): void
    {
        $submission = WrittenSubmission::query()->make([
            'id' => 99,
            'uploaded_at' => now()->subMinutes(1),
            'status' => WrittenSubmission::STATUS_UPLOADED,
        ]);

        $progress = WrittenSubmissionProgress::forSubmission($submission);

        $this->assertGreaterThan(0, $progress['percent']);
        $this->assertLessThan(30, $progress['percent']);
        $this->assertNotSame('', $progress['stage']);
    }
}
