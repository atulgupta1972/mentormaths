<?php

namespace App\Console\Commands;

use App\Services\PracticeCorrectionQueueService;
use Illuminate\Console\Command;

class BackfillPracticeCorrectionsCommand extends Command
{
    protected $signature = 'practice-corrections:backfill
                            {--student= : Limit to one student id}';

    protected $description = 'Build the wrong-answer correction queue from past guided, test, and written attempts';

    public function handle(PracticeCorrectionQueueService $queue): int
    {
        $studentId = $this->option('student');
        $studentId = is_numeric($studentId) ? (int) $studentId : null;

        $this->info($studentId
            ? "Backfilling correction queue for student #{$studentId}…"
            : 'Backfilling correction queue for all students…');

        $stats = $queue->backfill($studentId);

        $this->table(
            ['Source', 'Processed'],
            [
                ['Guided questions', (string) $stats['guided']],
                ['Batch attempts', (string) $stats['batch']],
                ['Written submissions', (string) $stats['written']],
            ],
        );

        $this->info("Pending correction items now: {$stats['pending']}");

        return self::SUCCESS;
    }
}
