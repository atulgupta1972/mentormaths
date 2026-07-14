<?php

namespace App\Support;

class ScoreLabel
{
    public static function percent(?int $score, ?int $max): ?int
    {
        if ($score === null || ! $max) {
            return null;
        }

        return (int) round(($score / $max) * 100);
    }

    public static function format(?int $score, ?int $max, bool $includeFraction = true): ?string
    {
        $percent = self::percent($score, $max);

        if ($percent === null) {
            return null;
        }

        if ($includeFraction && $max !== null && $score !== null) {
            return "{$percent}% ({$score}/{$max})";
        }

        return "{$percent}%";
    }

    /**
     * @param  iterable<int, array{latest_score?: ?int, latest_max_score?: ?int}>  $rows
     * @return array{score_total: int, max_total: int, percent: ?int, label: ?string}
     */
    public static function aggregateFromRows(iterable $rows): array
    {
        $scoreTotal = 0;
        $maxTotal = 0;

        foreach ($rows as $row) {
            $score = $row['latest_score'] ?? null;
            $max = $row['latest_max_score'] ?? null;

            if ($score === null || ! $max) {
                continue;
            }

            $scoreTotal += $score;
            $maxTotal += $max;
        }

        return [
            'score_total' => $scoreTotal,
            'max_total' => $maxTotal,
            'percent' => self::percent($scoreTotal, $maxTotal),
            'label' => self::format($scoreTotal, $maxTotal),
        ];
    }
}
