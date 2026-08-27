<?php

namespace App\Services;

use App\Models\StudentEnrollment;
use App\Support\StudentEngagementMetrics;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ClassHubProgressService
{
    public function __construct(
        private ClassCoverageService $classCoverage,
    ) {}

    /**
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function attach(Collection $enrollments, array $rows): array
    {
        $byEnrollmentId = $enrollments->keyBy('id');

        return array_map(function (array $row) use ($byEnrollmentId) {
            $enrollment = $byEnrollmentId->get($row['enrollment_id'] ?? null);

            if (! $enrollment) {
                $row['progress'] = $this->empty();

                return $row;
            }

            $from = $enrollment->academicYear?->starts_on
                ? Carbon::parse($enrollment->academicYear->starts_on)->startOfDay()
                : now()->subMonths(6)->startOfDay();
            $to = now()->endOfDay();

            $engagement = StudentEngagementMetrics::forEnrollment($enrollment, $from, $to);
            $performance = $this->classCoverage->studyPlanPerformance($enrollment) ?? [];

            $seconds = (int) ($engagement['time_spent_seconds'] ?? 0);
            $hours = $seconds > 0 ? round($seconds / 3600, 1) : 0.0;

            $row['progress'] = [
                'completion_pct' => $performance['completion_pct'] ?? null,
                'score_pct' => $performance['score_pct'] ?? null,
                'revision_done' => $performance['revision_done'] ?? $performance['correction_done'] ?? 0,
                'revision_pending' => max(
                    0,
                    (int) ($performance['revision_total'] ?? 0) - (int) ($performance['revision_done'] ?? 0),
                ) + (int) ($performance['correction_pending'] ?? 0),
                'open_wrongs' => $performance['open_wrongs'] ?? 0,
                'sets_done' => $performance['set_done'] ?? 0,
                'sets_total' => $performance['set_total'] ?? 0,
                'sums_attempted' => $performance['done'] ?? 0,
                'sums_total' => $performance['total'] ?? 0,
                'sums_correct' => $performance['correct'] ?? 0,
                'days_logged' => (int) ($engagement['days_logged_in'] ?? 0),
                'time_spent_label' => $engagement['time_spent_label'] ?? '0 sec',
                'time_spent_hours' => $hours,
            ];

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function empty(): array
    {
        return [
            'completion_pct' => null,
            'score_pct' => null,
            'revision_done' => 0,
            'revision_pending' => 0,
            'open_wrongs' => 0,
            'sets_done' => 0,
            'sets_total' => 0,
            'days_logged' => 0,
            'time_spent_label' => '0 sec',
            'time_spent_hours' => 0,
        ];
    }
}
