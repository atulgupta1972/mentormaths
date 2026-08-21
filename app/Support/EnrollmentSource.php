<?php

namespace App\Support;

final class EnrollmentSource
{
    public const INDIVIDUAL = 'individual';

    public const COACHING = 'coaching';

    public const SCHOOL = 'school';

    public const MENTOR_PARENT1 = 'parent1';

    public const MENTOR_PARENT2 = 'parent2';

    public const MENTOR_COACHING_TEACHER = 'coaching_teacher';

    public const MENTOR_USER = 'mentor_user';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::INDIVIDUAL, self::COACHING, self::SCHOOL];
    }

    /** Sources available in UI now (school master comes later). */
    public static function active(): array
    {
        return [self::INDIVIDUAL, self::COACHING];
    }

    public static function label(string $source): string
    {
        return match ($source) {
            self::INDIVIDUAL => 'Individual',
            self::COACHING => 'Coaching / Tuition',
            self::SCHOOL => 'School',
            default => $source,
        };
    }

    /** @return list<array{value: string, label: string, enabled: bool}> */
    public static function optionsForUi(): array
    {
        return [
            ['value' => self::INDIVIDUAL, 'label' => self::label(self::INDIVIDUAL), 'enabled' => true],
            ['value' => self::COACHING, 'label' => self::label(self::COACHING), 'enabled' => true],
            ['value' => self::SCHOOL, 'label' => self::label(self::SCHOOL).' (coming soon)', 'enabled' => false],
        ];
    }
}
