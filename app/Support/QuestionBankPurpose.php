<?php

namespace App\Support;

class QuestionBankPurpose
{
    public const PRACTICE_SET = 'practice_set';

    public const CHAPTER_TEST = 'chapter_test';

    public static function all(): array
    {
        return [
            self::PRACTICE_SET,
            self::CHAPTER_TEST,
        ];
    }

    public static function label(string $purpose): string
    {
        return match ($purpose) {
            self::CHAPTER_TEST => 'Chapter test',
            default => 'Practice set',
        };
    }

    public static function isChapterTest(?string $purpose): bool
    {
        return $purpose === null || $purpose === self::CHAPTER_TEST;
    }

    public static function isPracticeSet(?string $purpose): bool
    {
        return $purpose === self::PRACTICE_SET;
    }

    public static function normalize(?string $purpose): string
    {
        return in_array($purpose, self::all(), true)
            ? $purpose
            : self::PRACTICE_SET;
    }
}
