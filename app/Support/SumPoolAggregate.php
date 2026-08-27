<?php

namespace App\Support;

/**
 * Aggregate Completion% / Score% by sum counts (Total Pool), not by set counts.
 *
 * Completion% = total attempted sums / total pool sums
 * Score%      = total first-try correct sums / total pool sums
 */
class SumPoolAggregate
{
    /**
     * @param  array<string, mixed>  $item
     * @return array{pool: int, attempted: int, correct: int}
     */
    public static function sumsForItem(array $item): array
    {
        $poolMetrics = $item['pool_metrics'] ?? null;

        if (is_array($poolMetrics) && (int) ($poolMetrics['pool'] ?? 0) > 0) {
            return [
                'pool' => (int) $poolMetrics['pool'],
                'attempted' => (int) ($poolMetrics['attempted'] ?? 0),
                'correct' => (int) ($poolMetrics['correct'] ?? 0),
            ];
        }

        $questions = (int) ($item['question_count'] ?? 0);
        if ($questions <= 0) {
            return ['pool' => 0, 'attempted' => 0, 'correct' => 0];
        }

        $status = (string) ($item['status'] ?? '');
        if (in_array($status, ['done', 'green', 'green-late'], true)) {
            $pct = $item['score_percent'] ?? $item['latest_score_percent'] ?? null;
            $correct = $pct !== null
                ? (int) round(((float) $pct / 100) * $questions)
                : $questions;

            return [
                'pool' => $questions,
                'attempted' => $questions,
                'correct' => max(0, min($questions, $correct)),
            ];
        }

        return [
            'pool' => $questions,
            'attempted' => 0,
            'correct' => 0,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     completion_pct: int|null,
     *     score_pct: int|null,
     *     set_total: int,
     *     set_done: int
     * }
     */
    public static function fromItems(array $items): array
    {
        $pool = 0;
        $attempted = 0;
        $correct = 0;
        $setDone = 0;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $sums = self::sumsForItem($item);
            $pool += $sums['pool'];
            $attempted += $sums['attempted'];
            $correct += $sums['correct'];

            if (in_array((string) ($item['status'] ?? ''), ['done', 'green', 'green-late'], true)) {
                $setDone++;
            }
        }

        return [
            'pool' => $pool,
            'attempted' => $attempted,
            'correct' => $correct,
            'completion_pct' => $pool > 0 ? (int) round(($attempted / $pool) * 100) : null,
            'score_pct' => $pool > 0 ? (int) round(($correct / $pool) * 100) : null,
            'set_total' => count($items),
            'set_done' => $setDone,
        ];
    }
}
