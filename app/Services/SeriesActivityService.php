<?php

namespace App\Services;

use App\Models\Student;
use App\Models\StudentSeriesActivityDay;
use App\Support\AlgebraCardPack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * After formula drill, students alternate Mensuration Match and Algebra cards
 * by calendar day (first day = algebra when both are available).
 */
class SeriesActivityService
{
    public function __construct(
        private FormulaDrillSessionService $formulaService,
        private MensurationMatchService $mensuration,
    ) {}

    public function timezone(): string
    {
        return (string) config('formula_drill.timezone', 'Asia/Kolkata');
    }

    public function today(): Carbon
    {
        return now($this->timezone())->startOfDay();
    }

    /**
     * @return list<string>
     */
    public function availableKinds(Student $student): array
    {
        $kinds = [];

        if ($this->algebraAvailable($student)) {
            $kinds[] = StudentSeriesActivityDay::KIND_ALGEBRA;
        }

        if ($this->mensurationAvailable($student)) {
            $kinds[] = StudentSeriesActivityDay::KIND_MENSURATION;
        }

        return $kinds;
    }

    public function algebraAvailable(Student $student): bool
    {
        if (! $this->classNumberAtLeast($student, 6)) {
            return false;
        }

        return AlgebraCardPack::hasPlayableCards(AlgebraCardPack::FRACTION_SIMPLIFICATION);
    }

    public function mensurationAvailable(Student $student): bool
    {
        if (! Schema::hasTable('mensuration_match_sessions')) {
            return false;
        }

        if (! $this->formulaService->drillsUnlocked($student)) {
            return false;
        }

        $enrollment = $this->enrollment($student);
        if (! $enrollment?->gradeLevel) {
            return false;
        }

        return $this->mensuration->boardsForEnrollment($enrollment) !== [];
    }

    /**
     * Today's series activity kind, creating the day row if needed.
     * Null means nothing required in this slot.
     */
    public function todaysKind(Student $student): ?string
    {
        if (! Schema::hasTable('student_series_activity_days')) {
            // Pre-migration: keep mensuration-only behaviour.
            return $this->mensurationAvailable($student)
                ? StudentSeriesActivityDay::KIND_MENSURATION
                : null;
        }

        if (! $this->formulaService->drillsUnlocked($student)) {
            return null;
        }

        $available = $this->availableKinds($student);
        if ($available === []) {
            return null;
        }

        $day = $this->ensureTodayRow($student, $available);

        return $day->kind;
    }

    public function todaysRow(Student $student): ?StudentSeriesActivityDay
    {
        if (! Schema::hasTable('student_series_activity_days')) {
            return null;
        }

        return StudentSeriesActivityDay::query()
            ->where('student_id', $student->id)
            ->whereDate('activity_date', $this->today())
            ->first();
    }

    public function gatePassed(Student $student): bool
    {
        if (! $this->formulaService->drillsUnlocked($student)) {
            return true;
        }

        $kind = $this->todaysKind($student);
        if ($kind === null) {
            return true;
        }

        if ($kind === StudentSeriesActivityDay::KIND_ALGEBRA) {
            return $this->algebraCompletedToday($student);
        }

        return $this->mensuration->boardsCompletedToday($student);
    }

    public function isRequiredToday(Student $student): bool
    {
        if (! $this->formulaService->drillsUnlocked($student)) {
            return false;
        }

        return $this->todaysKind($student) !== null && ! $this->gatePassed($student);
    }

    public function algebraCompletedToday(Student $student): bool
    {
        $row = $this->todaysRow($student);

        return $row !== null
            && $row->kind === StudentSeriesActivityDay::KIND_ALGEBRA
            && $row->isCompleted();
    }

    public function markAlgebraComplete(Student $student, string $packKey): void
    {
        $this->markTodayComplete($student, StudentSeriesActivityDay::KIND_ALGEBRA, $packKey);
    }

    public function markMensurationComplete(Student $student): void
    {
        $this->markTodayComplete($student, StudentSeriesActivityDay::KIND_MENSURATION, null);
    }

    private function markTodayComplete(Student $student, string $kind, ?string $packKey): void
    {
        if (! Schema::hasTable('student_series_activity_days')) {
            return;
        }

        $row = $this->todaysRow($student);
        if ($row === null || $row->kind !== $kind) {
            return;
        }

        $row->forceFill([
            'pack_key' => $packKey ?? $row->pack_key,
            'completed_at' => $row->completed_at ?? now(),
        ])->save();
    }

    public function showRouteName(Student $student): string
    {
        return $this->todaysKind($student) === StudentSeriesActivityDay::KIND_ALGEBRA
            ? 'student.algebra-cards.show'
            : 'student.mensuration-match.show';
    }

    public function algebraPackKey(): string
    {
        return AlgebraCardPack::FRACTION_SIMPLIFICATION;
    }

    /**
     * @param  list<string>  $available
     */
    private function ensureTodayRow(Student $student, array $available): StudentSeriesActivityDay
    {
        $existing = $this->todaysRow($student);
        if ($existing !== null) {
            if (! in_array($existing->kind, $available, true)) {
                $existing->forceFill([
                    'kind' => $available[0],
                    'pack_key' => $available[0] === StudentSeriesActivityDay::KIND_ALGEBRA
                        ? $this->algebraPackKey()
                        : null,
                    'completed_at' => null,
                ])->save();
            }

            return $existing->fresh();
        }

        $kind = $this->pickKindForToday($student, $available);

        return StudentSeriesActivityDay::query()->create([
            'student_id' => $student->id,
            'activity_date' => $this->today()->toDateString(),
            'kind' => $kind,
            'pack_key' => $kind === StudentSeriesActivityDay::KIND_ALGEBRA
                ? $this->algebraPackKey()
                : null,
            'completed_at' => null,
        ]);
    }

    /**
     * @param  list<string>  $available
     */
    private function pickKindForToday(Student $student, array $available): string
    {
        if (count($available) === 1) {
            return $available[0];
        }

        $previous = StudentSeriesActivityDay::query()
            ->where('student_id', $student->id)
            ->whereDate('activity_date', '<', $this->today())
            ->orderByDesc('activity_date')
            ->first();

        if ($previous === null) {
            // Day 1 preference: algebra when both are available.
            return in_array(StudentSeriesActivityDay::KIND_ALGEBRA, $available, true)
                ? StudentSeriesActivityDay::KIND_ALGEBRA
                : $available[0];
        }

        $next = $previous->kind === StudentSeriesActivityDay::KIND_ALGEBRA
            ? StudentSeriesActivityDay::KIND_MENSURATION
            : StudentSeriesActivityDay::KIND_ALGEBRA;

        return in_array($next, $available, true) ? $next : $available[0];
    }

    private function classNumberAtLeast(Student $student, int $min): bool
    {
        $enrollment = $this->enrollment($student);
        if (! $enrollment?->gradeLevel) {
            return false;
        }

        return $this->mensuration->classNumber($enrollment->gradeLevel) >= $min;
    }

    private function enrollment(Student $student)
    {
        $enrollment = $student->currentEnrollment();
        if (! $enrollment) {
            $enrollment = $student->enrollments()->with('gradeLevel')->latest('id')->first();
        } else {
            $enrollment->loadMissing('gradeLevel');
        }

        return $enrollment;
    }
}
