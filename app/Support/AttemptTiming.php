<?php

namespace App\Support;

use App\Models\SetAttempt;
use Carbon\CarbonInterface;

class AttemptTiming
{
    /** Max seconds credited when a session was left open without an explicit pause (e.g. closed tab). */
    public const MAX_ORPHAN_SESSION_SECONDS = 900;

    /** Treat an open session shorter than this as continuous (same visit / Inertia reload). */
    public const CONTINUOUS_SESSION_SECONDS = 120;

    public static function elapsedSeconds(?CarbonInterface $startedAt, ?CarbonInterface $completedAt = null): int
    {
        if (! $startedAt) {
            return 0;
        }

        $completedAt ??= now();

        return max(0, (int) $startedAt->diffInSeconds($completedAt, true));
    }

    public static function resumeSession(SetAttempt $attempt): SetAttempt
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS) {
            return $attempt;
        }

        if ($attempt->active_session_started_at) {
            $openSeconds = self::elapsedSeconds($attempt->active_session_started_at);

            if ($openSeconds < self::CONTINUOUS_SESSION_SECONDS) {
                return $attempt;
            }

            $attempt->active_seconds = ($attempt->active_seconds ?? 0)
                + min($openSeconds, self::MAX_ORPHAN_SESSION_SECONDS);
        }

        $attempt->update([
            'active_seconds' => $attempt->active_seconds ?? 0,
            'active_session_started_at' => now(),
        ]);

        return $attempt->fresh();
    }

    public static function pauseSession(SetAttempt $attempt): SetAttempt
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || ! $attempt->active_session_started_at) {
            return $attempt;
        }

        $attempt->update([
            'active_seconds' => ($attempt->active_seconds ?? 0)
                + self::elapsedSeconds($attempt->active_session_started_at),
            'active_session_started_at' => null,
        ]);

        return $attempt->fresh();
    }

    public static function activeSeconds(SetAttempt $attempt): int
    {
        $total = $attempt->active_seconds ?? 0;

        if ($attempt->active_session_started_at) {
            $total += self::elapsedSeconds($attempt->active_session_started_at);
        }

        return $total;
    }

    public static function finalizeActiveTime(SetAttempt $attempt): int
    {
        self::pauseSession($attempt);

        return $attempt->fresh()->active_seconds ?? 0;
    }

    /**
     * @return array{active_seconds: int, active_session_started_at: ?string}
     */
    public static function payloadForAttempt(SetAttempt $attempt): array
    {
        return [
            'active_seconds' => $attempt->active_seconds ?? 0,
            'active_session_started_at' => $attempt->active_session_started_at?->toIso8601String(),
        ];
    }
}
