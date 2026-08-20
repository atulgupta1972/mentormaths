<?php

namespace App\Support;

class PracticeSetMasterProfile
{
    public const LEARNER = PracticeSetTier::STARTER;

    public const ACHIEVER = PracticeSetTier::BUILDER;

    public const EXPERT = PracticeSetTier::CHAMPION;

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(config('practice_set_masters.profiles', []));
    }

    /**
     * @return array{easy: int, medium: int, hard: int}
     */
    public static function marks(): array
    {
        $marks = config('practice_set_masters.marks', []);

        return [
            'easy' => (int) ($marks['easy'] ?? 1),
            'medium' => (int) ($marks['medium'] ?? 2),
            'hard' => (int) ($marks['hard'] ?? 3),
        ];
    }

    public static function markFor(string $difficulty): int
    {
        $key = self::normalizeDifficulty($difficulty);
        $marks = self::marks();

        return $marks[$key] ?? 1;
    }

    /**
     * Score for a mix: (easy×1) + (medium×2) + (hard×3) by default.
     */
    public static function score(int $easy, int $medium, int $hard): int
    {
        $marks = self::marks();

        return ($easy * $marks['easy']) + ($medium * $marks['medium']) + ($hard * $marks['hard']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function profile(string $tier): ?array
    {
        $profiles = config('practice_set_masters.profiles', []);

        return $profiles[$tier] ?? null;
    }

    /**
     * @return array{total: int, easy: int, medium: int, hard: int}
     */
    public static function counts(string $tier): array
    {
        $profile = self::profile($tier);

        if (! $profile) {
            return ['total' => 6, 'easy' => 2, 'medium' => 2, 'hard' => 2];
        }

        return [
            'total' => (int) ($profile['total'] ?? 0),
            'easy' => (int) ($profile['easy'] ?? 0),
            'medium' => (int) ($profile['medium'] ?? 0),
            'hard' => (int) ($profile['hard'] ?? 0),
        ];
    }

    /**
     * @return list<array{value: string, key: string, label: string, tagline: string, easy: int, medium: int, hard: int, total: int, score: int, color: string}>
     */
    public static function options(): array
    {
        return collect(config('practice_set_masters.profiles', []))
            ->map(function (array $profile, string $tier) {
                $easy = (int) ($profile['easy'] ?? 0);
                $medium = (int) ($profile['medium'] ?? 0);
                $hard = (int) ($profile['hard'] ?? 0);

                return [
                    'value' => $tier,
                    'key' => (string) ($profile['key'] ?? $tier),
                    'label' => (string) ($profile['label'] ?? PracticeSetTier::label($tier)),
                    'tagline' => (string) ($profile['tagline'] ?? PracticeSetTier::tagline($tier)),
                    'easy' => $easy,
                    'medium' => $medium,
                    'hard' => $hard,
                    'total' => (int) ($profile['total'] ?? ($easy + $medium + $hard)),
                    'score' => self::score($easy, $medium, $hard),
                    'color' => (string) ($profile['color'] ?? 'slate'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Distribute a profile's easy/medium/hard counts across N topics (as evenly as possible).
     *
     * @return list<array{easy: int, medium: int, hard: int}>
     */
    public static function distributeAcrossTopics(string $tier, int $topicCount): array
    {
        $topicCount = max(1, $topicCount);
        $counts = self::counts($tier);
        $rows = array_fill(0, $topicCount, ['easy' => 0, 'medium' => 0, 'hard' => 0]);

        foreach (['easy', 'medium', 'hard'] as $field) {
            $remaining = $counts[$field];
            $base = intdiv($remaining, $topicCount);
            $extra = $remaining % $topicCount;

            for ($i = 0; $i < $topicCount; $i++) {
                $rows[$i][$field] = $base + ($i < $extra ? 1 : 0);
            }
        }

        return $rows;
    }

    /**
     * Pick tier from difficulty counts — highest count wins; ties prefer Hard > Medium > Easy.
     */
    public static function tierFromDifficultyCounts(int $easy, int $medium, int $hard): string
    {
        $max = max($easy, $medium, $hard);

        if ($max <= 0) {
            return self::LEARNER;
        }

        if ($hard === $max) {
            return self::EXPERT;
        }

        if ($medium === $max) {
            return self::ACHIEVER;
        }

        return self::LEARNER;
    }

    public static function normalizeDifficulty(?string $difficulty): string
    {
        $value = strtolower(trim((string) $difficulty));

        return match ($value) {
            'easy', 'e' => 'easy',
            'hard', 'h' => 'hard',
            'medium', 'med', 'm' => 'medium',
            default => 'medium',
        };
    }

    public static function isValid(string $tier): bool
    {
        return isset(config('practice_set_masters.profiles')[$tier]);
    }
}
