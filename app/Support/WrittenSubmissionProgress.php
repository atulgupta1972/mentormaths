<?php

namespace App\Support;

use App\Models\WrittenSubmission;
use Illuminate\Support\Facades\Cache;

class WrittenSubmissionProgress
{
    /**
     * @return array{percent: int, stage: string}
     */
    public static function forSubmission(?WrittenSubmission $submission): array
    {
        if (! $submission) {
            return ['percent' => 0, 'stage' => ''];
        }

        if ($submission->status === WrittenSubmission::STATUS_GRADED) {
            return ['percent' => 100, 'stage' => 'Complete'];
        }

        if ($submission->status === WrittenSubmission::STATUS_FAILED) {
            return ['percent' => 0, 'stage' => 'Could not finish'];
        }

        $cached = $submission->id
            ? Cache::get(self::cacheKey($submission->id))
            : null;

        if (is_array($cached) && isset($cached['percent'], $cached['stage'])) {
            return [
                'percent' => max(0, min(100, (int) $cached['percent'])),
                'stage' => (string) $cached['stage'],
            ];
        }

        $minutes = self::checkingMinutes($submission);

        if ($submission->status === WrittenSubmission::STATUS_PROCESSING) {
            return [
                'percent' => min(92, 30 + ($minutes * 8)),
                'stage' => 'Checking with AI',
            ];
        }

        if ($minutes >= 10) {
            return [
                'percent' => 12,
                'stage' => 'Queued — waiting to start',
            ];
        }

        return [
            'percent' => min(25, 8 + ($minutes * 3)),
            'stage' => $minutes >= 2 ? 'Queued' : 'Starting…',
        ];
    }

    public static function update(WrittenSubmission $submission, int $percent, string $stage): void
    {
        Cache::put(self::cacheKey($submission->id), [
            'percent' => max(0, min(100, $percent)),
            'stage' => $stage,
        ], now()->addHour());
    }

    public static function clear(int $submissionId): void
    {
        Cache::forget(self::cacheKey($submissionId));
    }

    public static function checkingMinutes(?WrittenSubmission $submission): int
    {
        if (! $submission?->uploaded_at) {
            return 0;
        }

        return (int) round($submission->uploaded_at->diffInMinutes(now()));
    }

    private static function cacheKey(int $submissionId): string
    {
        return "written_submission_progress:{$submissionId}";
    }
}
