<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Worksheet;
use App\Support\PracticeSetMasterProfile;
use App\Support\PracticeSetTier;
use App\Support\WorksheetPurpose;
use Illuminate\Support\Collection;

class PracticeSetTierClassifier
{
    /**
     * @return array{scanned: int, updated: int, skipped: int, unchanged: int, by_tier: array<string, int>}
     */
    public function classifyAll(bool $dryRun = false): array
    {
        $stats = [
            'scanned' => 0,
            'updated' => 0,
            'skipped' => 0,
            'unchanged' => 0,
            'by_tier' => [
                PracticeSetTier::STARTER => 0,
                PracticeSetTier::BUILDER => 0,
                PracticeSetTier::CHAMPION => 0,
            ],
        ];

        Worksheet::query()
            ->with(['questions:id,difficulty'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $worksheets) use (&$stats, $dryRun) {
                foreach ($worksheets as $worksheet) {
                    $stats['scanned']++;

                    if ($this->shouldSkip($worksheet)) {
                        $stats['skipped']++;

                        continue;
                    }

                    $tier = $this->classifyWorksheet($worksheet);
                    $stats['by_tier'][$tier] = ($stats['by_tier'][$tier] ?? 0) + 1;

                    if ($worksheet->tier === $tier) {
                        $stats['unchanged']++;

                        continue;
                    }

                    if (! $dryRun) {
                        $worksheet->update(['tier' => $tier]);
                    }

                    $stats['updated']++;
                }
            });

        return $stats;
    }

    public function classifyWorksheet(Worksheet $worksheet): string
    {
        $questions = $worksheet->relationLoaded('questions')
            ? $worksheet->questions
            : $worksheet->questions()->get(['questions.id', 'questions.difficulty']);

        $easy = 0;
        $medium = 0;
        $hard = 0;

        foreach ($questions as $question) {
            /** @var Question $question */
            $raw = strtolower(trim((string) $question->difficulty));

            if ($raw === '' || ! in_array($raw, ['easy', 'e', 'medium', 'med', 'm', 'hard', 'h'], true)) {
                continue;
            }

            match (PracticeSetMasterProfile::normalizeDifficulty($raw)) {
                'easy' => $easy++,
                'hard' => $hard++,
                default => $medium++,
            };
        }

        if ($easy + $medium + $hard === 0) {
            return PracticeSetMasterProfile::LEARNER;
        }

        return PracticeSetMasterProfile::tierFromDifficultyCounts($easy, $medium, $hard);
    }

    private function shouldSkip(Worksheet $worksheet): bool
    {
        if ($worksheet->purpose === WorksheetPurpose::FORMULA
            || $worksheet->purpose === WorksheetPurpose::CATCH_UP) {
            return true;
        }

        $questions = $worksheet->relationLoaded('questions')
            ? $worksheet->questions
            : $worksheet->questions()->get(['questions.id']);

        return $questions->isEmpty();
    }
}
