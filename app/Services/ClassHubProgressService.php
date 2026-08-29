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
    /**
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public function attach(Collection $enrollments, array $rows): array
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

            $seconds = (int) ($engagement['time_spent_seconds'] ?? 0);
            $hours = $seconds > 0 ? round($seconds / 3600, 1) : 0.0;
            $setsDone = (int) $assignment['done'];
            $setsTotal = (int) $assignment['total'];
            $completion = $setsTotal > 0 ? (int) round(($setsDone / $setsTotal) * 100) : null;

            $row['progress'] = [
                'completion_pct' => $completion,
                'score_pct' => null,
                'revision_done' => 0,
                'revision_pending' => 0,
                'open_wrongs' => 0,
                'sets_done' => $setsDone,
                'sets_total' => $setsTotal,
                'days_logged' => (int) ($engagement['days_logged_in'] ?? 0),
                'time_spent_label' => $engagement['time_spent_label'] ?? '0 sec',
                'time_spent_hours' => $hours,
            ];

            return $row;
        }, $rows);
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
}
