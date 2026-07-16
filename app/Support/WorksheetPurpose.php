<?php

namespace App\Support;

class WorksheetPurpose
{
    public const STANDARD = 'standard';

    public const CATCH_UP = 'catch_up';

    public static function all(): array
    {
        return [
            self::STANDARD,
            self::CATCH_UP,
        ];
    }

    public static function label(string $purpose): string
    {
        return match ($purpose) {
            self::CATCH_UP => 'Catch-up',
            default => 'Practice',
        };
    }
}
