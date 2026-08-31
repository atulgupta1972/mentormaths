<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\StudentEnrollment;
use App\Support\StudentEngagementMetrics;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function attachEngagement(Collection $enrollments, array $rows): array
    {
        if ($enrollments->isEmpty() || $rows === []) {
            return $rows;
        }

        try {
            $engagementById = StudentEngagementMetrics::forManyEnrollments($enrollments, now()->endOfDay());
        } catch (Throwable $e) {
            Log::error('Class hub failed to batch-load engagement metrics.', ['message' => $e->getMessage()]);

            return $rows;
        }

        return array_map(function (array $row) use ($engagementById) {
            $enrollmentId = (int) ($row['enrollment_id'] ?? 0);
            $engagement = $engagementById[$enrollmentId] ?? null;

            if (! $engagement || ! is_array($row['progress'] ?? null)) {
                return $row;
            }

            $seconds = (int) ($engagement['time_spent_seconds'] ?? 0);
            $row['progress'] = array_merge($row['progress'], [
                'days_logged' => (int) ($engagement['days_logged_in'] ?? 0),
                'time_spent_label' => $engagement['time_spent_label'] ?? '0 sec',
                'time_spent_hours' => $seconds > 0 ? round($seconds / 3600, 1) : 0.0,
            ]);

            return $row;
        }, $rows);
    }

    /**
     * Study-plan sums only — engagement is attached on the first paint via attachEngagement().
     *
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @return array<int, array<string, mixed>>
     */
    public function studyPerformanceMetricsByEnrollment(Collection $enrollments): array
    {
        $metrics = [];

        foreach ($enrollments as $enrollment) {
            $metrics[(int) $enrollment->id] = $this->studyPerformanceMetricsForEnrollment($enrollment);
        }

        return $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    private function studyPerformanceMetricsForEnrollment(StudentEnrollment $enrollment): array
    {
        $performance = [];

        try {
            $performance = $this->classCoverage->classHubPerformance($enrollment) ?? [];
        } catch (Throwable $e) {
            Log::error('Class hub failed to load study-plan score for a student.', [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'message' => $e->getMessage(),
            ]);
        }

        return [
            'completion_pct' => $this->resolveDisplayCompletionPct($performance),
            'score_pct' => $this->resolveDisplayScorePct($performance),
            'revision_done' => $performance['revision_done'] ?? $performance['correction_done'] ?? 0,
            'revision_pending' => max(
                0,
                (int) ($performance['revision_total'] ?? 0) - (int) ($performance['revision_done'] ?? 0),
            ) + (int) ($performance['correction_pending'] ?? 0),
            'open_wrongs' => $performance['open_wrongs'] ?? 0,
            'sums_attempted' => $performance['done'] ?? 0,
            'sums_total' => $performance['total'] ?? 0,
            'sums_correct' => $performance['correct'] ?? 0,
        ];
    }

    /**
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function attach(Collection $enrollments, array $rows): array
    {
        $rows = $this->attachFast($enrollments, $rows);

        return $this->mergeStudyPlanMetrics($rows, $this->studyPlanMetricsByEnrollment($enrollments));
    }

    /**
     * Fast path: assignment set counts only — used for the first paint on class hub pages.
     *
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function attachFast(Collection $enrollments, array $rows): array
    {
        $byEnrollmentId = $enrollments->keyBy('id');
        $assignmentProgress = $this->assignmentProgressByEnrollment($enrollments);

        return array_map(function (array $row) use ($byEnrollmentId, $assignmentProgress) {
            $enrollment = $byEnrollmentId->get($row['enrollment_id'] ?? null)
                ?? $byEnrollmentId->get((int) ($row['enrollment_id'] ?? 0));

            if (! $enrollment) {
                $row['progress'] = $this->empty();

                return $row;
            }

            $assignment = $assignmentProgress[$enrollment->id]
                ?? $assignmentProgress[(string) $enrollment->id]
                ?? ['done' => 0, 'total' => 0];

            $setsDone = (int) $assignment['done'];
            $setsTotal = (int) $assignment['total'];

            $row['progress'] = [
                ...$this->empty(),
                'sets_done' => $setsDone,
                'sets_total' => $setsTotal,
            ];

            return $row;
        }, $rows);
    }

    /**
     * Score, revision, and engagement — one study-plan load per student.
     *
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @return array<int, array<string, mixed>>
     */
    public function studyPlanMetricsByEnrollment(Collection $enrollments): array
    {
        $metrics = [];

        foreach ($enrollments as $enrollment) {
            $metrics[(int) $enrollment->id] = $this->studyPlanMetricsForEnrollment($enrollment);
        }

        return $metrics;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $metricsByEnrollment
     * @return list<array<string, mixed>>
     */
    public function mergeStudyPlanMetrics(array $rows, array $metricsByEnrollment): array
    {
        return array_map(function (array $row) use ($metricsByEnrollment) {
            $enrollmentId = (int) ($row['enrollment_id'] ?? 0);
            $metrics = $metricsByEnrollment[$enrollmentId] ?? null;

            if (! $metrics || ! is_array($row['progress'] ?? null)) {
                return $row;
            }

            $row['progress'] = array_merge($row['progress'], $metrics);

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function studyPlanMetricsForEnrollment(StudentEnrollment $enrollment): array
    {
        $from = $enrollment->academicYear?->starts_on
            ? Carbon::parse($enrollment->academicYear->starts_on)->startOfDay()
            : now()->subMonths(6)->startOfDay();
        $to = now()->endOfDay();

        $engagement = [
            'days_logged_in' => 0,
            'time_spent_seconds' => 0,
            'time_spent_label' => '0 sec',
        ];

        try {
            $engagement = StudentEngagementMetrics::forEnrollment($enrollment, $from, $to);
        } catch (Throwable $e) {
            Log::error('Class hub failed to load engagement for a student.', [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'message' => $e->getMessage(),
            ]);
        }

        $performance = [];

        try {
            $performance = $this->classCoverage->classHubPerformance($enrollment) ?? [];
        } catch (Throwable $e) {
            Log::error('Class hub failed to load study-plan score for a student.', [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'message' => $e->getMessage(),
            ]);
        }

        $seconds = (int) ($engagement['time_spent_seconds'] ?? 0);
        $hours = $seconds > 0 ? round($seconds / 3600, 1) : 0.0;

        return [
            'completion_pct' => $this->resolveDisplayCompletionPct($performance),
            'score_pct' => $this->resolveDisplayScorePct($performance),
            'revision_done' => $performance['revision_done'] ?? $performance['correction_done'] ?? 0,
            'revision_pending' => max(
                0,
                (int) ($performance['revision_total'] ?? 0) - (int) ($performance['revision_done'] ?? 0),
            ) + (int) ($performance['correction_pending'] ?? 0),
            'open_wrongs' => $performance['open_wrongs'] ?? 0,
            'sums_attempted' => $performance['done'] ?? 0,
            'sums_total' => $performance['total'] ?? 0,
            'sums_correct' => $performance['correct'] ?? 0,
            'days_logged' => (int) ($engagement['days_logged_in'] ?? 0),
            'time_spent_label' => $engagement['time_spent_label'] ?? '0 sec',
            'time_spent_hours' => $hours,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function withEmptyProgress(array $rows): array
    {
        return array_map(function (array $row) {
            $row['progress'] = $this->empty();

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
            'sums_attempted' => 0,
            'sums_total' => 0,
            'sums_correct' => 0,
            'days_logged' => 0,
            'time_spent_label' => '0 sec',
            'time_spent_hours' => 0,
        ];
    }

    /**
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @return array<int|string, array{done: int, total: int}>
     */
    private function assignmentProgressByEnrollment(Collection $enrollments): array
    {
        $ids = $enrollments->pluck('id')->filter()->all();

        if ($ids === []) {
            return [];
        }

        $result = [];
        foreach ($ids as $id) {
            $result[$id] = ['done' => 0, 'total' => 0];
        }

        try {
            $rows = SetAssignment::query()
                ->selectRaw('student_enrollment_id, status, count(*) as c')
                ->whereIn('student_enrollment_id', $ids)
                ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
                ->groupBy('student_enrollment_id', 'status')
                ->get();
        } catch (Throwable $e) {
            Log::error('Class hub failed to load assignment counts.', ['message' => $e->getMessage()]);

            return $result;
        }

        foreach ($rows as $row) {
            $id = $row->student_enrollment_id;
            $count = (int) $row->c;
            if (! isset($result[$id])) {
                $result[$id] = ['done' => 0, 'total' => 0];
            }
            $result[$id]['total'] += $count;
            if ($row->status === SetAssignment::STATUS_COMPLETED) {
                $result[$id]['done'] += $count;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $performance
     */
    private function resolveDisplayCompletionPct(array $performance): ?int
    {
        if (($performance['completion_pct'] ?? null) !== null) {
            return (int) $performance['completion_pct'];
        }

        $pool = (int) ($performance['total'] ?? 0);
        $attempted = (int) ($performance['done'] ?? 0);
        if ($pool > 0) {
            return (int) round(($attempted / $pool) * 100);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $performance
     */
    private function resolveDisplayScorePct(array $performance): ?int
    {
        if (($performance['score_pct'] ?? null) !== null) {
            return (int) $performance['score_pct'];
        }

        $attempted = (int) ($performance['done'] ?? 0);
        $correct = (int) ($performance['correct'] ?? 0);
        if ($attempted > 0) {
            return (int) round(($correct / $attempted) * 100);
        }

        $total = (int) ($performance['total'] ?? 0);
        if ($total > 0 && $correct > 0) {
            return (int) round(($correct / $total) * 100);
        }

        return null;
    }
}
