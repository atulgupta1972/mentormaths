<?php

namespace App\Support;

class WorksheetPurpose
{
    public const STANDARD = 'standard';

    public const CATCH_UP = 'catch_up';

    public const FORMULA = 'formula';

    public const EXAM_PREP = 'exam_prep';

    public static function all(): array
    {
        return [
            self::STANDARD,
            self::CATCH_UP,
            self::FORMULA,
            self::EXAM_PREP,
        ];
    }

    public static function label(string $purpose): string
    {
        return match ($purpose) {
            self::CATCH_UP => 'Catch-up',
            self::FORMULA => 'Formula / concept',
            self::EXAM_PREP => 'Exam prep',
            default => 'Practice',
        };
    }
}
