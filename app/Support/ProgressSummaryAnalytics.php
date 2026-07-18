<?php

namespace App\Support;

class ProgressSummaryAnalytics
{
    /**
     * @param  list<array<string, mixed>>  $completed
     * @return list<array{
     *     chapter_name: string,
     *     sets_count: int,
     *     score_total: int,
     *     max_total: int,
     *     percent: ?int,
     *     label: ?string
     * }>
     */
    public static function chapterPerformance(array $completed): array
    {
        if ($completed === []) {
            return [];
        }

        return collect($completed)
            ->groupBy(fn (array $row) => ProgressSummaryTable::chapterName($row))
            ->map(function ($rows, string $chapterName) {
                $items = $rows->values()->all();
                $aggregate = ScoreLabel::aggregateFromRows($items);

                return [
                    'chapter_name' => $chapterName,
                    'sets_count' => count($items),
                    'score_total' => $aggregate['score_total'],
                    'max_total' => $aggregate['max_total'],
                    'percent' => $aggregate['percent'],
                    'label' => $aggregate['label'],
                ];
            })
            ->sortBy('chapter_name')
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $completed
     * @return list<array{
     *     date: string,
     *     date_label: string,
     *     sets_count: int,
     *     score_total: int,
     *     max_total: int,
     *     percent: ?int,
     *     label: ?string
     * }>
     */
    public static function datePerformance(array $completed): array
    {
        if ($completed === []) {
            return [];
        }

        return collect($completed)
            ->filter(fn (array $row) => ! empty($row['submitted_at']))
            ->groupBy(fn (array $row) => substr((string) $row['submitted_at'], 0, 10))
            ->map(function ($rows, string $date) {
                $items = $rows->values()->all();
                $aggregate = ScoreLabel::aggregateFromRows($items);

                return [
                    'date' => $date,
                    'date_label' => DateLabels::formatDate($date),
                    'sets_count' => count($items),
                    'score_total' => $aggregate['score_total'],
                    'max_total' => $aggregate['max_total'],
                    'percent' => $aggregate['percent'],
                    'label' => $aggregate['label'],
                ];
            })
            ->sortBy('date')
            ->values()
            ->all();
    }
}
