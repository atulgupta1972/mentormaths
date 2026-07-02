<?php

namespace App\Support;

class PracticeSetScope
{
    public const TOPIC = 'topic';

    public const CHAPTER = 'chapter';

    public static function all(): array
    {
        return [
            self::TOPIC,
            self::CHAPTER,
        ];
    }

    public static function label(string $scope): string
    {
        return match ($scope) {
            self::CHAPTER => 'Chapter test',
            default => 'Topic practice',
        };
    }
}
