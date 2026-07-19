<?php

namespace App\Jobs;

use App\Models\WrittenSubmission;
use App\Services\WrittenGradingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class GradeWrittenSubmissionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $submissionId) {}

    public function handle(WrittenGradingService $gradingService): void
    {
        $submission = WrittenSubmission::query()->find($this->submissionId);

        if (! $submission || $submission->status !== WrittenSubmission::STATUS_UPLOADED) {
            return;
        }

        try {
            $gradingService->grade($submission);
        } catch (\Throwable $exception) {
            Log::error('Written submission grading failed', [
                'submission_id' => $this->submissionId,
                'message' => $exception->getMessage(),
            ]);

            $submission->update([
                'status' => WrittenSubmission::STATUS_FAILED,
                'grading_error' => $exception->getMessage(),
            ]);
        }
    }
}
