<?php

namespace App\Console\Commands;

use App\Services\PracticeSetTierClassifier;
use App\Support\PracticeSetTier;
use Illuminate\Console\Command;

class ClassifyPracticeSetTiers extends Command
{
    protected $signature = 'practice-sets:classify-tiers
                            {--dry-run : Show what would change without updating worksheets}';

    protected $description = 'Put each practice/written/fill-blank set into Learner / Achiever / Expert by majority question difficulty';

    public function handle(PracticeSetTierClassifier $classifier): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run — no worksheets will be updated.');
        }

        $stats = $classifier->classifyAll($dryRun);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', $stats['scanned']],
                ['Updated', $stats['updated']],
                ['Unchanged', $stats['unchanged']],
                ['Skipped (chapter test / formula / catch-up / empty)', $stats['skipped']],
                [PracticeSetTier::label(PracticeSetTier::STARTER), $stats['by_tier'][PracticeSetTier::STARTER] ?? 0],
                [PracticeSetTier::label(PracticeSetTier::BUILDER), $stats['by_tier'][PracticeSetTier::BUILDER] ?? 0],
                [PracticeSetTier::label(PracticeSetTier::CHAMPION), $stats['by_tier'][PracticeSetTier::CHAMPION] ?? 0],
            ],
        );

        $this->info($dryRun
            ? 'Dry run complete. Re-run without --dry-run to apply.'
            : 'Classification complete.');

        return self::SUCCESS;
    }
}
