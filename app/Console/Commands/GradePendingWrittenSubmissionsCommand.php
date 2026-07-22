<?php

namespace App\Console\Commands;

use App\Models\WrittenSubmission;
use App\Services\WrittenSubmissionService;
use Illuminate\Console\Command;

class GradePendingWrittenSubmissionsCommand extends Command
{
    protected $signature = 'written-submissions:grade-pending';

    protected $description = 'Grade written homework uploads stuck in uploaded/processing status';

    public function handle(WrittenSubmissionService $submissionService): int
    {
        $pending = WrittenSubmission::query()
            ->where(function ($query) {
                $query->where('status', WrittenSubmission::STATUS_UPLOADED)
                    ->orWhere(function ($inner) {
                        $inner->where('status', WrittenSubmission::STATUS_PROCESSING)
                            ->where('updated_at', '<', now()->subMinutes(5));
                    });
            })
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending written submissions.');

            return self::SUCCESS;
        }

        $graded = 0;
        $failed = 0;

        foreach ($pending as $submission) {
            $this->line("Grading submission #{$submission->id}…");

            if ($submissionService->runGrading($submission->id)) {
                $graded++;
            } else {
                $failed++;
            }
        }

        $this->info("Done. Graded: {$graded}, failed: {$failed}.");

        return self::SUCCESS;
    }
}
