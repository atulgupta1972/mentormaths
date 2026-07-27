<?php

namespace App\Support;

use Carbon\Carbon;

class ProgressSummaryTable
{
    public const FALLBACK_CHAPTER = 'Other';

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{chapter_name: string, rows: list<array<string, mixed>>}>
     */
    public static function groupByChapter(array $rows, string $sortKey): array
    {
        if ($rows === []) {
            return [];
        }

        return collect($rows)
            ->groupBy(fn (array $row) => self::chapterName($row))
            ->map(fn ($items, string $chapterName) => [
                'chapter_name' => $chapterName,
                'rows' => $items->values()->all(),
            ])
            ->sortBy(fn (array $group) => collect($group['rows'])->min(
                fn (array $row) => $row[$sortKey] ?? '9999-12-31 23:59:59',
            ))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function chapterName(array $row): string
    {
        $name = trim((string) ($row['chapter_name'] ?? ''));

        return $name !== '' ? $name : self::FALLBACK_CHAPTER;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function detailLabel(array $row): string
    {
        $topic = trim((string) ($row['topic_name'] ?? ''));

        if ($topic !== '') {
            return $topic;
        }

        $title = trim((string) ($row['display_title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        return (string) ($row['kind_label'] ?? 'Practice');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function submittedDateLabel(array $row): ?string
    {
        if (empty($row['submitted_at'])) {
            return null;
        }

        return DateLabels::formatDate(substr((string) $row['submitted_at'], 0, 10));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function targetDateLabel(array $row): string
    {
        if (empty($row['target_date'])) {
            return '—';
        }

        return DateLabels::formatDate((string) $row['target_date']);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function scoreLabel(array $row): string
    {
        return $row['latest_score_label']
            ?? ScoreLabel::format($row['latest_score'] ?? null, $row['latest_max_score'] ?? null)
            ?? '—';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function reviewLabel(array $row): string
    {
        $reviewCount = count($row['review_items'] ?? []);

        if ($reviewCount === 0) {
            return '—';
        }

        return $reviewCount.' need review';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function attemptSuffix(array $row): string
    {
        if (($row['latest_attempt_number'] ?? 0) <= 1) {
            return '';
        }

        return ' · Attempt '.$row['latest_attempt_number'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{pending_days: ?int, pending_days_label: string}
     */
    public static function pendingDaysMeta(array $row, ?Carbon $asOf = null): array
    {
        $asOf = ($asOf ?? now())->copy()->startOfDay();

        if (! empty($row['is_overdue']) && ! empty($row['target_date'])) {
            $due = Carbon::parse((string) $row['target_date'])->startOfDay();
            $days = (int) $due->diffInDays($asOf, false);

            if ($days > 0) {
                return [
                    'pending_days' => $days,
                    'pending_days_label' => $days === 1 ? '1 day overdue' : "{$days} days overdue",
                ];
            }

            return [
                'pending_days' => 0,
                'pending_days_label' => 'Due today',
            ];
        }

        $startAt = $row['reassigned_at'] ?? $row['assigned_at'] ?? null;

        if ($startAt) {
            $assigned = Carbon::parse(substr((string) $startAt, 0, 10))->startOfDay();
            $days = (int) $assigned->diffInDays($asOf);

            if ($days === 0) {
                return [
                    'pending_days' => 0,
                    'pending_days_label' => 'Assigned today',
                ];
            }

            return [
                'pending_days' => $days,
                'pending_days_label' => $days === 1 ? 'Pending 1 day' : "Pending {$days} days",
            ];
        }

        if (! empty($row['target_date'])) {
            $due = Carbon::parse((string) $row['target_date'])->startOfDay();
            $daysUntilDue = (int) $asOf->diffInDays($due, false);

            if ($daysUntilDue > 0) {
                return [
                    'pending_days' => $daysUntilDue,
                    'pending_days_label' => $daysUntilDue === 1 ? 'Due in 1 day' : "Due in {$daysUntilDue} days",
                ];
            }
        }

        return [
            'pending_days' => null,
            'pending_days_label' => '—',
        ];
    }
}
