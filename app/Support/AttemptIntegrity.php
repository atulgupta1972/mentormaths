<?php

namespace App\Support;

use App\Models\SetAttempt;
use App\Models\StudentEnrollment;

class AttemptIntegrity
{
    /**
     * @return array{
     *     enabled: bool,
     *     mode: 'strict'|'light'|'off',
     *     require_fullscreen: bool,
     *     track_tab_leaves: bool
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
                'require_fullscreen' => true,
                'track_tab_leaves' => true,
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
        ];
    }

    /**
     * @return array{
     *     enabled: bool,
     *     mode: 'strict'|'light'|'off',
     *     require_fullscreen: bool,
     *     track_tab_leaves: bool,
     *     tab_leave_count: int
     * }
     */
    public static function payloadForAttempt(SetAttempt $attempt, bool $isTest): array
    {
        $attempt->loadMissing('assignment.enrollment.gradeLevel');

        $config = self::configFor($attempt->assignment?->enrollment, $isTest);

        return [
            ...$config,
            'tab_leave_count' => $attempt->tab_leave_count ?? 0,
        ];
    }

    /**
     * @return array{enabled: bool, mode: 'off', require_fullscreen: bool, track_tab_leaves: bool}
     */
    private static function disabled(): array
    {
        return [
            'enabled' => false,
            'mode' => 'off',
            'require_fullscreen' => false,
            'track_tab_leaves' => false,
        ];
    }
}
