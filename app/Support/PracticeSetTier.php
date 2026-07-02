<?php

namespace App\Support;

class PracticeSetTier
{
    public const STARTER = 'starter';

    public const BUILDER = 'builder';

    public const CHAMPION = 'champion';

    public const CHAPTER_TEST = 'chapter_test';

    public static function all(): array
    {
        return [
            self::STARTER,
            self::BUILDER,
            self::CHAMPION,
            self::CHAPTER_TEST,
        ];
    }

    public static function codeLetter(string $tier): string
    {
        return match ($tier) {
            self::STARTER => 'S',
            self::BUILDER => 'B',
            self::CHAMPION => 'C',
            self::CHAPTER_TEST => 'T',
            default => 'S',
        };
    }

    public static function label(string $tier): string
    {
        return match ($tier) {
            self::STARTER => 'Starter',
            self::BUILDER => 'Builder',
            self::CHAMPION => 'Champion',
            self::CHAPTER_TEST => 'Chapter test',
            default => ucfirst($tier),
        };
    }

    public static function tagline(string $tier): string
    {
        return match ($tier) {
            self::STARTER => 'Getting comfortable',
            self::BUILDER => 'Building confidence',
            self::CHAMPION => 'Exam-ready challenge',
            self::CHAPTER_TEST => 'Mixed topics from the whole chapter',
            default => '',
        };
    }

    public static function topicTiers(): array
    {
        return [
            self::STARTER,
            self::BUILDER,
            self::CHAMPION,
        ];
    }

    public static function options(): array
    {
        return collect(self::topicTiers())->map(fn (string $tier) => [
            'value' => $tier,
            'label' => self::label($tier),
            'tagline' => self::tagline($tier),
        ])->all();
    }
}
