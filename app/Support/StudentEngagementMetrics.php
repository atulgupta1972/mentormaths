<?php

namespace App\Support;

use App\Models\SetAttempt;
use App\Models\StudentEnrollment;
use App\Models\UserLoginDay;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StudentEngagementMetrics
{
    /**
     * @return array{
     *     date_from: string,
     *     date_to: string,
     *     total_days: int,
     *     days_logged_in: int,
     *     days_not_logged_in: int,
     *     time_spent_seconds: int,
     *     time_spent_label: string,
     * }
     */
    public static function forEnrollment(StudentEnrollment $enrollment, Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        if ($from->gt($to)) {
            [$from, $to] = [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
        }

        $totalDays = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1;
        $loggedDates = self::loggedDates($enrollment, $from, $to);
        $daysLoggedIn = count($loggedDates);
        $daysNotLoggedIn = max(0, $totalDays - $daysLoggedIn);
        $seconds = self::timeSpentSeconds($enrollment, $from, $to);

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total_days' => $totalDays,
            'days_logged_in' => $daysLoggedIn,
            'days_not_logged_in' => $daysNotLoggedIn,
            'time_spent_seconds' => $seconds,
            'time_spent_label' => self::formatDuration($seconds),
        ];
    }

    public static function formatDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);

        if ($seconds < 60) {
            return $seconds.' sec';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rem = $minutes % 60;

        return $rem > 0 ? "{$hours}h {$rem}m" : "{$hours}h";
    }

    /**
     * @return list<string> Y-m-d dates
     */
    private static function loggedDates(StudentEnrollment $enrollment, Carbon $from, Carbon $to): array
    {
        $dates = [];

        $userId = $enrollment->student?->user_id;

        if ($userId && self::supportsLoginDays()) {
            $presence = UserLoginDay::query()
                ->where('user_id', $userId)
                ->whereBetween('login_date', [$from->toDateString(), $to->toDateString()])
                ->pluck('login_date')
                ->map(fn ($d) => Carbon::parse($d)->toDateString())
                ->all();

            foreach ($presence as $date) {
                $dates[$date] = true;
            }
        }

        if ($userId) {
            $lastSeen = $enrollment->student?->user?->last_seen_at;
            if ($lastSeen && $lastSeen->between($from, $to)) {
                $dates[$lastSeen->toDateString()] = true;
            }
        }

        $assignmentIds = $enrollment->setAssignments()->pluck('id');

        if ($assignmentIds->isNotEmpty()) {
            $attemptDates = SetAttempt::query()
                ->whereIn('set_assignment_id', $assignmentIds)
                ->where(function ($query) use ($from, $to) {
                    $query->whereBetween('started_at', [$from, $to])
                        ->orWhereBetween('completed_at', [$from, $to]);
                })
                ->get(['started_at', 'completed_at']);

            foreach ($attemptDates as $attempt) {
                foreach (['started_at', 'completed_at'] as $field) {
                    $value = $attempt->{$field};
                    if ($value && $value->between($from, $to)) {
                        $dates[$value->toDateString()] = true;
                    }
                }
            }
        }

        $keys = array_keys($dates);
        sort($keys);

        return $keys;
    }

    private static function timeSpentSeconds(StudentEnrollment $enrollment, Carbon $from, Carbon $to): int
    {
        $assignmentIds = $enrollment->setAssignments()->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return 0;
        }

        $rows = SetAttempt::query()
            ->whereIn('set_assignment_id', $assignmentIds)
            ->where(function ($query) use ($from, $to) {
                $query->whereBetween('started_at', [$from, $to])
                    ->orWhereBetween('completed_at', [$from, $to])
                    ->orWhere(function ($q) use ($from, $to) {
                        $q->where('status', SetAttempt::STATUS_IN_PROGRESS)
                            ->whereBetween('updated_at', [$from, $to]);
                    });
            })
            ->get(['active_seconds', 'time_seconds', 'active_session_started_at', 'status']);

        $total = 0;

        foreach ($rows as $attempt) {
            $seconds = (int) ($attempt->active_seconds ?? 0);

            if ($seconds <= 0) {
                $seconds = (int) ($attempt->time_seconds ?? 0);
            }

            if (
                $attempt->status === SetAttempt::STATUS_IN_PROGRESS
                && $attempt->active_session_started_at
                && $attempt->active_session_started_at->between($from, $to)
            ) {
                $seconds += max(0, (int) $attempt->active_session_started_at->diffInSeconds(now(), true));
            }

            $total += $seconds;
        }

        return $total;
    }

    private static function supportsLoginDays(): bool
    {
        static $supported = null;

        if ($supported === null) {
            try {
                $supported = Schema::hasTable('user_login_days');
            } catch (\Throwable) {
                $supported = false;
            }
        }

        return $supported;
    }

    public static function recordLoginDay(int $userId, ?Carbon $date = null): void
    {
        if (! self::supportsLoginDays()) {
            return;
        }

        $loginDate = ($date ?? now())->toDateString();

        try {
            DB::table('user_login_days')->insertOrIgnore([
                'user_id' => $userId,
                'login_date' => $loginDate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never block requests if presence tracking fails.
        }
    }
}
