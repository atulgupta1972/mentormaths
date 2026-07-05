<?php

namespace App\Support;

use Carbon\CarbonInterface;

class AttemptTiming
{
    public static function elapsedSeconds(?CarbonInterface $startedAt, ?CarbonInterface $completedAt = null): int
    {
        if (! $startedAt) {
            return 0;
        }

        $completedAt ??= now();

        return max(0, (int) $startedAt->diffInSeconds($completedAt, true));
    }
}
