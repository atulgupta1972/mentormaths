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

    public static function codePrefix(string $tier, bool $fillInBlank = false): string
    {
        $letter = self::codeLetter($tier);

        if ($fillInBlank && $tier !== self::CHAPTER_TEST) {
            return $letter.'F';
        }

        return $letter;
    }

    /**
     * SF721 / BF721 / CF721 are fill-in-blank practice. S721 is MCQ.
     * An empty collection's every() is true, so never infer type from questions alone.
     */
    public static function codeLooksFillInBlank(?string $setCode): bool
    {
        return preg_match('/^[SBC]F\d+/i', (string) $setCode) === 1;
    }

    /**
     * SW721 / BW721 / CW721 are written sheets.
     */
    public static function codeLooksWritten(?string $setCode): bool
    {
        return preg_match('/^[SBC]W\d+/i', (string) $setCode) === 1;
    }

    /**
     * S721 → SF721 when looking up the MCQ sibling of a fill-in-blank set (or vice versa).
     */
    public static function siblingFillBlankCode(?string $setCode): ?string
    {
        $code = strtoupper(trim((string) $setCode));

        if (preg_match('/^([SBC])F(\d+)$/', $code, $matches) === 1) {
            return $matches[1].$matches[2];
        }

        if (preg_match('/^([SBC])(\d+)$/', $code, $matches) === 1) {
            return $matches[1].'F'.$matches[2];
        }

        return null;
    }

    public static function label(string $tier): string
    {
        return match ($tier) {
            self::STARTER => 'Learner',
            self::BUILDER => 'Achiever',
            self::CHAMPION => 'Expert',
            self::CHAPTER_TEST => 'Chapter test',
            default => ucfirst($tier),
        };
    }

    public static function tagline(string $tier): string
    {
        return match ($tier) {
            self::STARTER => 'Mostly easy — build confidence',
            self::BUILDER => 'Mostly medium — stretch with a few hard',
            self::CHAMPION => 'All hard — exam-ready challenge',
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
