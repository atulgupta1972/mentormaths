<?php

namespace App\Support;

class QuestionBankPurpose
{
    public const PRACTICE_SET = 'practice_set';

    public const CHAPTER_TEST = 'chapter_test';

    public const FORMULA = 'formula';

    public static function all(): array
    {
        return [
            self::PRACTICE_SET,
            self::CHAPTER_TEST,
            self::FORMULA,
        ];
    }

    public static function label(string $purpose): string
    {
        return match ($purpose) {
            self::CHAPTER_TEST => 'Chapter test',
            self::FORMULA => 'Formula / concept',
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

    public static function isFormula(?string $purpose): bool
    {
        return $purpose === self::FORMULA;
    }

    public static function normalize(?string $purpose): string
    {
        return in_array($purpose, self::all(), true)
            ? $purpose
            : self::PRACTICE_SET;
    }
}
