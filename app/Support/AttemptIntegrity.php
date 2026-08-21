<?php

namespace App\Support;

use App\Models\SetAttempt;
use App\Models\StudentEnrollment;

class AttemptIntegrity
{
    /** After this many tab/app switches, the attempt is locked. */
    public const TAB_LEAVE_LOCK_LIMIT = 2;

    /**
     * @return array{
     *     enabled: bool,
     *     mode: 'strict'|'light'|'off',
     *     require_fullscreen: bool,
     *     track_tab_leaves: bool,
     *     tab_leave_lock_limit: int
     * }
     */
    public static function configFor(?StudentEnrollment $enrollment, bool $isTest): array
    {
        $grade = $enrollment?->gradeLevel;

        if ($isTest) {
            if (! ($grade?->protect_test_attempts ?? true)) {
                return self::disabled();
            }

            return [
                'enabled' => true,
                'mode' => 'strict',
                'require_fullscreen' => false,
                'track_tab_leaves' => true,
                'tab_leave_lock_limit' => self::TAB_LEAVE_LOCK_LIMIT,
            ];
        }

        if (! ($grade?->protect_practice_attempts ?? true)) {
            return self::disabled();
        }

        return [
            'enabled' => true,
            'mode' => 'light',
            'require_fullscreen' => false,
            'track_tab_leaves' => true,
            'tab_leave_lock_limit' => self::TAB_LEAVE_LOCK_LIMIT,
        ];
    }

    public static function isLocked(SetAttempt $attempt, ?array $config = null): bool
    {
        if ($config === null) {
            $attempt->loadMissing('assignment.enrollment.gradeLevel', 'assignment.practiceSet');
            $isTest = (bool) $attempt->assignment?->practiceSet?->isChapterTest();
            $config = self::configFor($attempt->assignment?->enrollment, $isTest);
        }

        if (! ($config['track_tab_leaves'] ?? false)) {
            return false;
        }

        $limit = (int) ($config['tab_leave_lock_limit'] ?? self::TAB_LEAVE_LOCK_LIMIT);

        return $limit > 0 && (int) ($attempt->tab_leave_count ?? 0) >= $limit;
    }

    /**
     * @return array{
     *     enabled: bool,
     *     mode: 'strict'|'light'|'off',
     *     require_fullscreen: bool,
     *     track_tab_leaves: bool,
     *     tab_leave_lock_limit: int,
     *     tab_leave_count: int,
     *     locked: bool
     * }
     */
    public static function payloadForAttempt(SetAttempt $attempt, bool $isTest): array
    {
        $attempt->loadMissing('assignment.enrollment.gradeLevel');

        $config = self::configFor($attempt->assignment?->enrollment, $isTest);
        $count = (int) ($attempt->tab_leave_count ?? 0);

        return [
            ...$config,
            'tab_leave_count' => $count,
            'locked' => ($config['track_tab_leaves'] ?? false)
                && $count >= (int) ($config['tab_leave_lock_limit'] ?? self::TAB_LEAVE_LOCK_LIMIT),
        ];
    }

    /**
     * @return array{enabled: bool, mode: 'off', require_fullscreen: bool, track_tab_leaves: bool, tab_leave_lock_limit: int}
     */
    private static function disabled(): array
    {
        return [
            'enabled' => false,
            'mode' => 'off',
            'require_fullscreen' => false,
            'track_tab_leaves' => false,
            'tab_leave_lock_limit' => self::TAB_LEAVE_LOCK_LIMIT,
        ];
    }
}
