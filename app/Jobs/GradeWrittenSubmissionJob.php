<?php

namespace App\Jobs;

use App\Services\WrittenSubmissionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GradeWrittenSubmissionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(public int $submissionId) {}

    public function handle(WrittenSubmissionService $submissionService): void
    {
        $submissionService->runGrading($this->submissionId);
    }
}
