<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\BasicsDrillProgress;
use App\Models\BasicsDrillSession;
use App\Models\BasicsFactStat;
use App\Models\StudentEnrollment;
use Illuminate\Support\Collection;

class BasicsDrillCoverageService
{
    public function __construct(
        private BasicsDrillSettingsService $settings,
        private BasicsDrillReportService $report,
    ) {}

    /**
     * Class-wise snapshot of last table/squares/cubes and current mistakes.
     *
     * @return array{
     *     totals: array{students: int, with_mistakes: int, never_started: int},
     *     classes: list<array<string, mixed>>
     * }
     */
    public function classMatrix(): array
    {
        $empty = [
            'totals' => ['students' => 0, 'with_mistakes' => 0, 'never_started' => 0],
            'classes' => [],
        ];

        $year = AcademicYear::active();
        if (! $year) {
            return $empty;
        }

        $enrollments = StudentEnrollment::query()
            ->where('academic_year_id', $year->id)
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->with(['student.user:id,is_active', 'gradeLevel:id,name,sort_order'])
            ->get()
            ->filter(function (StudentEnrollment $enrollment) {
                $student = $enrollment->student;
                if (! $student) {
                    return false;
                }

                return ! $student->user || $student->user->isActiveAccount();
            })
            ->values();

        if ($enrollments->isEmpty()) {
            return $empty;
        }

        $studentIds = $enrollments->pluck('student_id')->unique()->all();

        $progressByStudent = BasicsDrillProgress::query()
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');

        $latestByStudent = $this->latestSessions($studentIds);
        $mistakesByStudent = $this->mistakesByStudent($studentIds);

        $settingsByGrade = [];
        $classes = [];
        $totals = ['students' => 0, 'with_mistakes' => 0, 'never_started' => 0];

        $grouped = $enrollments
            ->groupBy('grade_level_id')
            ->sortBy(fn (Collection $rows) => $rows->first()?->gradeLevel?->sort_order ?? 99);

        foreach ($grouped as $gradeId => $rows) {
            $grade = $rows->first()?->gradeLevel;
            $settings = $settingsByGrade[$gradeId] ??= $this->settings->forGradeLevelId((int) $gradeId);

            $students = $rows
                ->sortBy(fn (StudentEnrollment $enrollment) => mb_strtolower($enrollment->student->name))
                ->map(function (StudentEnrollment $enrollment) use ($progressByStudent, $latestByStudent, $mistakesByStudent, $settings, &$totals) {
                    $student = $enrollment->student;
                    $progress = $progressByStudent->get($student->id);
                    $session = $latestByStudent->get($student->id);
                    $misses = $mistakesByStudent->get($student->id, collect())->values()->all();

                    $totals['students']++;
                    if ($misses !== []) {
                        $totals['with_mistakes']++;
                    }
                    if (! $session) {
                        $totals['never_started']++;
                    }

                    $nextTable = $progress
                        ? $this->settings->firstAllowedAtOrAfter((int) $progress->next_table, $settings)
                        : $this->settings->firstAllowedAtOrAfter((int) ($settings['table_from'] ?? 2), $settings);

                    return [
                        'student_id' => $student->id,
                        'student_name' => $student->name,
                        'student_url' => route('admin.students.show', $student),
                        'last_date' => $session?->drill_date?->timezone(config('basics_drill.timezone', 'Asia/Kolkata'))->format('j M'),
                        'last_status' => $session?->status,
                        'last_table' => $settings['tables_enabled'] ? $session?->table_number : null,
                        'last_squares' => ($settings['squares_enabled'] ?? false)
                            ? $this->batchLabel($session?->square_batch_start, $settings, 'square')
                            : null,
                        'last_cubes' => ($settings['cubes_enabled'] ?? false)
                            ? $this->batchLabel($session?->cube_batch_start, $settings, 'cube')
                            : null,
                        'next_table' => ($settings['tables_enabled'] ?? false) ? $nextTable : null,
                        'next_squares' => ($settings['squares_enabled'] ?? false)
                            ? $this->batchLabel($progress?->square_batch_start, $settings, 'square')
                            : null,
                        'next_cubes' => ($settings['cubes_enabled'] ?? false)
                            ? $this->batchLabel($progress?->cube_batch_start, $settings, 'cube')
                            : null,
                        'miss_count' => count($misses),
                        'misses' => $misses,
                    ];
                })
                ->values()
                ->all();

            $classes[] = [
                'grade_level_id' => (int) $gradeId,
                'grade_name' => $grade?->name ?? 'Class',
                'student_count' => count($students),
                'mistake_count' => count(array_filter($students, fn (array $row) => $row['miss_count'] > 0)),
                'never_started_count' => count(array_filter($students, fn (array $row) => $row['last_date'] === null)),
                'students' => $students,
            ];
        }

        return [
            'totals' => $totals,
            'classes' => $classes,
        ];
    }

    /**
     * @param  list<int>  $studentIds
     * @return Collection<int, BasicsDrillSession>
     */
    private function latestSessions(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        $latestIds = BasicsDrillSession::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', [
                BasicsDrillSession::STATUS_IN_PROGRESS,
                BasicsDrillSession::STATUS_COMPLETED,
            ])
            ->groupBy('student_id')
            ->pluck('id');

        return BasicsDrillSession::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy('student_id');
    }

    /**
     * @param  list<int>  $studentIds
     * @return Collection<int, Collection<int, array<string, mixed>>>
     */
    private function mistakesByStudent(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return BasicsFactStat::query()
            ->whereIn('student_id', $studentIds)
            ->where(function ($query) {
                $query->where('needs_review', true)
                    ->orWhere('times_failed', '>', 0);
            })
            ->orderByDesc('needs_review')
            ->orderByDesc('times_failed')
            ->get()
            ->groupBy('student_id')
            ->map(function (Collection $rows) {
                return $rows->take(4)->map(fn (BasicsFactStat $stat) => [
                    'fact_type' => $stat->fact_type,
                    'fact_key' => $stat->fact_key,
                    'label' => $this->report->labelForStat($stat),
                    'times_failed' => $stat->times_failed,
                    'needs_review' => $stat->needs_review,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function batchLabel(?int $start, array $settings, string $kind): ?string
    {
        if ($start === null) {
            return null;
        }

        $isSquare = $kind === 'square';
        $from = (int) ($isSquare ? ($settings['square_from'] ?? 2) : ($settings['cube_from'] ?? 2));
        $to = (int) ($isSquare ? ($settings['square_to'] ?? 30) : ($settings['cube_to'] ?? 13));
        $perDay = (int) ($isSquare ? ($settings['squares_per_day'] ?? 5) : ($settings['cubes_per_day'] ?? 3));
        $suffix = $isSquare ? '²' : '³';

        $start = max($from, min($start, $to));
        $end = min($to, $start + max(1, $perDay) - 1);

        if ($start === $end) {
            return $start.$suffix;
        }

        return $start.$suffix.'–'.$end.$suffix;
    }
}
