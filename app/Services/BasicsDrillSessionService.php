<?php

namespace App\Services;

use App\Models\BasicsDrillItem;
use App\Models\BasicsDrillProgress;
use App\Models\BasicsDrillSession;
use App\Models\BasicsFactStat;
use App\Models\Student;
use App\Support\AnswerValidationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class BasicsDrillSessionService
{
    public function __construct(
        private BasicsDrillSettingsService $settingsService,
        private DailyDrillCorrectionService $correctionService,
        private FormulaDrillSessionService $formulaService,
        private AnswerValidationService $answerValidation,
    ) {}

    public function todayDate(): Carbon
    {
        return now(config('basics_drill.timezone', 'Asia/Kolkata'))->startOfDay();
    }

    public function gatePassed(Student $student): bool
    {
        if (! $this->settingsService->isEnabledForEnrollment($student->currentEnrollment())) {
            return ! $this->correctionService->needsFinalCorrection($student);
        }

        $session = $this->todaysSession($student);

        if ($this->correctionService->needsFinalCorrection($student)) {
            return false;
        }

        return $session?->isComplete() ?? false;
    }

    public function todaysSession(Student $student): ?BasicsDrillSession
    {
        return BasicsDrillSession::query()
            ->where('student_id', $student->id)
            ->whereDate('drill_date', $this->todayDate())
            ->with('items')
            ->first();
    }

    public function getOrCreateTodaysSession(Student $student): BasicsDrillSession
    {
        $existing = $this->todaysSession($student);

        if ($existing) {
            if ($this->shouldEnterFinalCorrection($student, $existing)) {
                return $this->beginFinalCorrection($existing);
            }

            return $this->recoverStuckSession($existing);
        }

        $settings = $this->settingsService->forStudent($student);
        $progress = $this->progressFor($student);

        if (! $this->settingsService->isEnabledForEnrollment($student->currentEnrollment())) {
            return BasicsDrillSession::query()->create([
                'student_id' => $student->id,
                'student_enrollment_id' => $student->currentEnrollment()?->id,
                'drill_date' => $this->todayDate(),
                'status' => BasicsDrillSession::STATUS_SKIPPED,
                'phase' => BasicsDrillSession::PHASE_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        $tableNumber = $this->resolveTableNumber($progress, $settings);
        $squareStart = $this->clampSquareBatchStart($progress->square_batch_start, $settings);
        $cubeStart = $this->clampCubeBatchStart($progress->cube_batch_start, $settings);

        $firstPhase = BasicsDrillSession::PHASE_COMPLETED;
        if ($settings['tables_enabled'] && $tableNumber !== null) {
            $firstPhase = BasicsDrillSession::PHASE_TABLE_SHOW;
        } elseif ($settings['squares_enabled'] && $this->squareBatchNumbers($squareStart, $settings) !== []) {
            $firstPhase = BasicsDrillSession::PHASE_SQUARES_SHOW;
        } elseif ($settings['cubes_enabled'] && $this->cubeBatchNumbers($cubeStart, $settings) !== []) {
            $firstPhase = BasicsDrillSession::PHASE_CUBES_SHOW;
        }

        if ($firstPhase === BasicsDrillSession::PHASE_COMPLETED) {
            if ($this->formulaService->gatePassed($student)
                && $this->correctionService->hasUncorrectedFailures($student)) {
                $session = BasicsDrillSession::query()->create([
                    'student_id' => $student->id,
                    'student_enrollment_id' => $student->currentEnrollment()?->id,
                    'drill_date' => $this->todayDate(),
                    'status' => BasicsDrillSession::STATUS_IN_PROGRESS,
                    'phase' => BasicsDrillSession::PHASE_FINAL_CORRECTION,
                ]);

                return $this->beginFinalCorrection($session);
            }

            return BasicsDrillSession::query()->create([
                'student_id' => $student->id,
                'student_enrollment_id' => $student->currentEnrollment()?->id,
                'drill_date' => $this->todayDate(),
                'status' => BasicsDrillSession::STATUS_SKIPPED,
                'phase' => BasicsDrillSession::PHASE_COMPLETED,
                'completed_at' => now(),
            ]);
        }

        return BasicsDrillSession::query()->create([
            'student_id' => $student->id,
            'student_enrollment_id' => $student->currentEnrollment()?->id,
            'drill_date' => $this->todayDate(),
            'status' => BasicsDrillSession::STATUS_IN_PROGRESS,
            'phase' => $firstPhase,
            'table_number' => $tableNumber,
            'square_batch_start' => $squareStart,
            'cube_batch_start' => $cubeStart,
        ]);
    }

    /**
     * If a drill/retry phase has no pending items left (e.g. last answer saved but
     * the advance request failed), move the session forward so the student is not stuck
     * on a blank screen.
     */
    public function recoverStuckSession(BasicsDrillSession $session): BasicsDrillSession
    {
        if ($session->isComplete()) {
            return $session;
        }

        $session->loadMissing(['items', 'student']);

        if (! $session->student) {
            return $session;
        }

        try {
            if ($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
                return $this->recoverStuckFinalCorrection($session);
            }

            $settings = $this->settingsService->forStudent($session->student);
            $guard = 0;

            while ($guard < 8 && $this->isStuckDrillPhase($session)) {
                $guard++;
                $this->advanceAfterPhase($session->fresh(['items', 'student']), $settings);
                $session = $session->fresh(['items', 'student']);

                if (! $session || $session->isComplete()) {
                    break;
                }

                // Landed on a show phase — that is a valid screen.
                if (str_ends_with($session->phase, '_show')) {
                    break;
                }
            }
        } catch (\Throwable) {
            return $session->fresh(['items']) ?? $session;
        }

        return $session->fresh(['items', 'student']) ?? $session;
    }

    /**
     * Correction round finished in the DB (or emptied) but the session never closed —
     * common after a dropped answer response. Close it or restore the next pending item.
     */
    private function recoverStuckFinalCorrection(BasicsDrillSession $session): BasicsDrillSession
    {
        $session->loadMissing(['items', 'student']);

        if ($this->nextPendingCorrectionItem($session)) {
            return $session;
        }

        $correctionItems = $session->items->where('round', BasicsDrillItem::ROUND_CORRECTION);

        foreach ($correctionItems->where('status', '!=', BasicsDrillItem::STATUS_CORRECT) as $item) {
            $item->update(['status' => BasicsDrillItem::STATUS_PENDING]);
        }

        $session = $session->fresh(['items', 'student']);

        if ($this->nextPendingCorrectionItem($session)) {
            return $session;
        }

        if ($session->student && $correctionItems->isEmpty()
            && $this->correctionService->hasUncorrectedFailures($session->student)) {
            $this->correctionService->ensureCorrectionItems($session);

            return $session->fresh(['items', 'student']) ?? $session;
        }

        foreach ($session->items->where('round', BasicsDrillItem::ROUND_CORRECTION) as $item) {
            if ($item->status === BasicsDrillItem::STATUS_CORRECT) {
                $this->correctionService->markSourcesCorrected($item);
            }
        }

        $this->completeSession($session);

        return $session->fresh(['items', 'student']) ?? $session;
    }

    private function isStuckDrillPhase(BasicsDrillSession $session): bool
    {
        if ($session->isComplete()) {
            return false;
        }

        if (! str_ends_with($session->phase, '_drill') && ! str_ends_with($session->phase, '_retry')) {
            return false;
        }

        $session->loadMissing('items');

        return $this->currentItem($session) === null;
    }

    public function startDrill(BasicsDrillSession $session): BasicsDrillSession
    {
        $phase = $session->phase;

        if (str_ends_with($phase, '_show')) {
            $drillPhase = str_replace('_show', '_drill', $phase);
            $session->update(['phase' => $drillPhase]);
            $this->ensureItemsForPhase($session->fresh(['items']));
            $session = $session->fresh(['items', 'student']);

            // Empty batch (misconfigured range) — don't leave a blank drill screen.
            if ($this->currentItem($session) === null) {
                $settings = $this->settingsService->forStudent($session->student);
                $this->advanceAfterPhase($session, $settings);

                return $session->fresh(['items', 'student']);
            }
        }

        return $session->fresh(['items']);
    }

    public function submitAnswer(BasicsDrillItem $item, ?string $answer, bool $timedOut): array
    {
        $session = $item->session()->with(['items', 'student'])->firstOrFail();
        $settings = $this->settingsService->forStudent($session->student);

        if ($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
            if ($item->isFormulaFillBlank()) {
                return $this->submitCorrectionFillBlank($item, $answer, $settings);
            }

            return $this->submitCorrectionNumericAnswer($item, $answer, $timedOut, $settings);
        }

        $correctAnswer = (string) $item->correct_answer;
        $normalized = preg_replace('/\D+/', '', (string) $answer) ?: '';
        $isCorrect = ! $timedOut && $normalized !== '' && $normalized === $correctAnswer;

        $this->recordStat($session->student_id, $item, $isCorrect);

        if ($isCorrect) {
            $item->update(['status' => BasicsDrillItem::STATUS_CORRECT]);
        } else {
            $item->update(['status' => BasicsDrillItem::STATUS_FAILED]);
        }

        $session = $session->fresh(['items']);

        if ($isCorrect) {
            $next = $this->nextPendingItem($session);

            if ($next) {
                return [
                    'correct' => true,
                    'reveal' => false,
                    'next_item' => $this->formatItem($next),
                    'session' => $this->formatSession($session->fresh(['items']), $settings),
                ];
            }

            return $this->advanceAfterPhase($session, $settings);
        }

        return [
            'correct' => false,
            'reveal' => true,
            'correct_answer' => (int) $item->correct_answer,
            'prompt' => $item->promptLabel(),
            'item_id' => $item->id,
            'session' => $this->formatSession($session->fresh(['items']), $settings),
        ];
    }

    public function submitCorrectionFillBlank(BasicsDrillItem $item, ?string $answer, array $settings): array
    {
        $session = $item->session()->with(['items', 'student'])->firstOrFail();

        abort_unless($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION, 422);
        abort_unless($item->isFormulaFillBlank(), 422);

        $result = $this->correctionService->submitFillBlankAnswer($item, (string) ($answer ?? ''));
        $session = $session->fresh(['items', 'student']);

        if ($result['correct']) {
            $next = $this->nextPendingCorrectionItem($session);

            if ($next) {
                return [
                    ...$result,
                    'next_item' => $this->formatItem($next),
                    'session' => $this->formatSession($session, $settings),
                ];
            }

            $this->completeSession($session);

            return $this->formatCompletionPayload($session, $settings, $result);
        }

        return [
            ...$result,
            'session' => $this->formatSession($session, $settings),
        ];
    }

    public function submitCorrectionMcq(BasicsDrillItem $item, int $optionId): array
    {
        $session = $item->session()->with(['items', 'student'])->firstOrFail();
        $settings = $this->settingsService->forStudent($session->student);

        abort_unless($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION, 422);
        abort_unless($item->isFormulaMcq(), 422);

        $result = $this->correctionService->submitMcqAnswer($item, $optionId);
        $session = $session->fresh(['items', 'student']);

        if ($result['correct']) {
            $next = $this->nextPendingCorrectionItem($session);

            if ($next) {
                return [
                    ...$result,
                    'next_item' => $this->formatItem($next),
                    'session' => $this->formatSession($session, $settings),
                ];
            }

            $this->completeSession($session);

            return $this->formatCompletionPayload($session, $settings, $result);
        }

        return [
            ...$result,
            'session' => $this->formatSession($session, $settings),
        ];
    }

    public function acknowledgeReveal(BasicsDrillItem $item): array
    {
        $session = $item->session()->with(['items', 'student'])->firstOrFail();
        $settings = $this->settingsService->forStudent($session->student);

        if ($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
            return [
                'next_item' => $this->formatItem($item),
                'session' => $this->formatSession($session, $settings),
                'retry_same' => true,
            ];
        }

        $item->update(['status' => BasicsDrillItem::STATUS_REVEALED]);

        $session = $session->fresh(['items']);
        $next = $this->nextPendingItem($session);

        if ($next) {
            return [
                'next_item' => $this->formatItem($next),
                'session' => $this->formatSession($session->fresh(['items']), $settings),
            ];
        }

        return $this->advanceAfterPhase($session, $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function formatSession(BasicsDrillSession $session, ?array $settings = null): array
    {
        $settings ??= $this->settingsService->forStudent($session->student);
        $current = $this->currentItem($session);

        $isFinalCorrection = $session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION;
        $correctionIntro = null;

        if ($isFinalCorrection && $session->student) {
            $correctionStarted = $session->items
                ->where('round', BasicsDrillItem::ROUND_CORRECTION)
                ->contains(fn (BasicsDrillItem $item) => $item->status !== BasicsDrillItem::STATUS_PENDING);

            if (! $correctionStarted) {
                $stats = $this->correctionService->mainRoundFirstTryStats($session->student);
                $copy = $this->correctionService->motivationalCopy($stats['percent'], $stats['to_fix']);
                $correctionIntro = [
                    ...$stats,
                    ...$copy,
                ];
            }
        }

        return [
            'id' => $session->id,
            'status' => $session->status,
            'phase' => $session->phase,
            'table_number' => $session->table_number,
            'square_batch_start' => $session->square_batch_start,
            'cube_batch_start' => $session->cube_batch_start,
            'seconds_per_blank' => $settings['seconds_per_blank'],
            'chart' => $this->chartForPhase($session, $settings),
            'current_item' => $current ? $this->formatItem($current) : null,
            'progress_label' => $this->progressLabel($session),
            'is_show_phase' => str_ends_with($session->phase, '_show'),
            'is_final_correction' => $isFinalCorrection,
            'correction_intro' => $correctionIntro,
            'correction_total' => $isFinalCorrection
                ? $session->items->where('round', BasicsDrillItem::ROUND_CORRECTION)->count()
                : 0,
            'is_complete' => $session->isComplete(),
        ];
    }

    public function completeSession(BasicsDrillSession $session): void
    {
        if ($session->status === BasicsDrillSession::STATUS_COMPLETED) {
            return;
        }

        DB::transaction(function () use ($session) {
            $session->update([
                'status' => BasicsDrillSession::STATUS_COMPLETED,
                'phase' => BasicsDrillSession::PHASE_COMPLETED,
                'completed_at' => now(),
            ]);

            if ($session->table_number === null
                && $session->square_batch_start === null
                && $session->cube_batch_start === null) {
                return;
            }

            $settings = $this->settingsService->forStudent($session->student);
            $progress = $this->progressFor($session->student);

            if ($settings['tables_enabled'] && $session->table_number !== null) {
                $next = $this->settingsService->nextTableAfter((int) $session->table_number, $settings);
                if ($next !== null) {
                    $progress->update(['next_table' => $next]);
                }
            }

            if ($settings['squares_enabled'] && $session->square_batch_start !== null) {
                $nextStart = $session->square_batch_start + ($settings['squares_per_day'] ?? 5);
                $maxStart = ($settings['square_to'] ?? 30) - ($settings['squares_per_day'] ?? 5) + 1;
                if ($nextStart > max($settings['square_from'] ?? 2, $maxStart)) {
                    $nextStart = $settings['square_from'] ?? 2;
                }
                $progress->update(['square_batch_start' => $nextStart]);
            }

            if ($settings['cubes_enabled'] && $session->cube_batch_start !== null) {
                $nextStart = $session->cube_batch_start + ($settings['cubes_per_day'] ?? 3);
                $maxStart = ($settings['cube_to'] ?? 13) - ($settings['cubes_per_day'] ?? 3) + 1;
                if ($nextStart > max($settings['cube_from'] ?? 2, $maxStart)) {
                    $nextStart = $settings['cube_from'] ?? 2;
                }
                $progress->update(['cube_batch_start' => $nextStart]);
            }
        });
    }

    private function advanceAfterPhase(BasicsDrillSession $session, array $settings): array
    {
        $nextPhase = $this->nextPhaseAfter($session, $settings);

        if ($nextPhase === BasicsDrillSession::PHASE_COMPLETED) {
            if ($this->correctionService->ensureCorrectionItems($session->fresh(['items', 'student']))) {
                $session->update(['phase' => BasicsDrillSession::PHASE_FINAL_CORRECTION]);
                $fresh = $session->fresh(['items', 'student']);
                $next = $this->nextPendingCorrectionItem($fresh);

                return [
                    'correct' => true,
                    'reveal' => false,
                    'phase_change' => BasicsDrillSession::PHASE_FINAL_CORRECTION,
                    'next_item' => $next ? $this->formatItem($next) : null,
                    'session' => $this->formatSession($fresh, $settings),
                ];
            }

            $this->completeSession($session);

            return $this->formatCompletionPayload($session, $settings, [
                'correct' => true,
                'reveal' => false,
            ]);
        }

        $session->update(['phase' => $nextPhase]);

        return [
            'correct' => true,
            'reveal' => false,
            'phase_change' => $nextPhase,
            'next_item' => null,
            'session' => $this->formatSession($session->fresh(['items']), $settings),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function formatCompletionPayload(BasicsDrillSession $session, array $settings, array $payload): array
    {
        $session->loadMissing('student');
        $summary = $session->student
            ? $this->correctionService->completionSummary($session->student)
            : null;

        return [
            ...$payload,
            'completed' => true,
            'redirect' => route('dashboard'),
            'completion_summary' => $summary,
            'session' => $this->formatSession($session->fresh(['items']), $settings),
        ];
    }

    private function beginFinalCorrection(BasicsDrillSession $session): BasicsDrillSession
    {
        $this->correctionService->ensureCorrectionItems($session);
        $session->update([
            'status' => BasicsDrillSession::STATUS_IN_PROGRESS,
            'phase' => BasicsDrillSession::PHASE_FINAL_CORRECTION,
        ]);

        return $session->fresh(['items', 'student']);
    }

    private function shouldEnterFinalCorrection(Student $student, BasicsDrillSession $session): bool
    {
        if ($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
            return false;
        }

        if ($session->isComplete() && $this->correctionService->hasUncorrectedFailures($student)) {
            return true;
        }

        return $session->status === BasicsDrillSession::STATUS_SKIPPED
            && $this->formulaService->gatePassed($student)
            && $this->correctionService->hasUncorrectedFailures($student);
    }

    /**
     * @return array<string, mixed>
     */
    private function submitCorrectionNumericAnswer(
        BasicsDrillItem $item,
        ?string $answer,
        bool $timedOut,
        array $settings,
    ): array {
        $session = $item->session()->with(['items', 'student'])->firstOrFail();
        $correctAnswer = (string) $item->correct_answer;
        $normalized = preg_replace('/\D+/', '', (string) $answer) ?: '';
        $isCorrect = ! $timedOut && $normalized !== '' && $normalized === $correctAnswer;

        if ($isCorrect) {
            $item->update(['status' => BasicsDrillItem::STATUS_CORRECT]);
            $this->correctionService->markSourcesCorrected($item);
            $session = $session->fresh(['items', 'student']);
            $next = $this->nextPendingCorrectionItem($session);

            if ($next) {
                return [
                    'correct' => true,
                    'reveal' => false,
                    'next_item' => $this->formatItem($next),
                    'session' => $this->formatSession($session, $settings),
                ];
            }

            $this->completeSession($session);

            return $this->formatCompletionPayload($session, $settings, [
                'correct' => true,
                'reveal' => false,
            ]);
        }

        return [
            'correct' => false,
            'reveal' => true,
            'correct_answer' => (int) $item->correct_answer,
            'prompt' => $item->promptLabel(),
            'item_id' => $item->id,
            'session' => $this->formatSession($session->fresh(['items']), $settings),
        ];
    }

    private function nextPendingCorrectionItem(BasicsDrillSession $session): ?BasicsDrillItem
    {
        return $session->items
            ->where('round', BasicsDrillItem::ROUND_CORRECTION)
            ->firstWhere('status', BasicsDrillItem::STATUS_PENDING);
    }

    private function nextPhaseAfter(BasicsDrillSession $session, array $settings): string
    {
        return match ($session->phase) {
            BasicsDrillSession::PHASE_TABLE_DRILL => $settings['squares_enabled']
                ? BasicsDrillSession::PHASE_SQUARES_SHOW
                : ($settings['cubes_enabled']
                    ? BasicsDrillSession::PHASE_CUBES_SHOW
                    : BasicsDrillSession::PHASE_COMPLETED),
            BasicsDrillSession::PHASE_SQUARES_DRILL => $settings['cubes_enabled']
                ? BasicsDrillSession::PHASE_CUBES_SHOW
                : BasicsDrillSession::PHASE_COMPLETED,
            BasicsDrillSession::PHASE_CUBES_DRILL => BasicsDrillSession::PHASE_COMPLETED,
            default => BasicsDrillSession::PHASE_COMPLETED,
        };
    }

    private function ensureItemsForPhase(BasicsDrillSession $session): void
    {
        $factType = $this->factTypeForPhase($session->phase);
        $round = str_ends_with($session->phase, '_retry')
            ? BasicsDrillItem::ROUND_RETRY
            : BasicsDrillItem::ROUND_MAIN;

        if ($factType === null) {
            return;
        }

        if ($session->items()->where('fact_type', $factType)->where('round', $round)->exists()) {
            return;
        }

        $settings = $this->settingsService->forStudent($session->student);
        $facts = match ($factType) {
            BasicsDrillItem::TYPE_TABLE => $this->tableFacts($session->table_number, $settings),
            BasicsDrillItem::TYPE_SQUARE => $this->squareFacts($session->square_batch_start, $settings),
            BasicsDrillItem::TYPE_CUBE => $this->cubeFacts($session->cube_batch_start, $settings),
            default => [],
        };

        $this->createItems($session, $facts, $round);
    }

    private function factTypeForPhase(string $phase): ?string
    {
        if (str_contains($phase, 'table')) {
            return BasicsDrillItem::TYPE_TABLE;
        }

        if (str_contains($phase, 'square')) {
            return BasicsDrillItem::TYPE_SQUARE;
        }

        if (str_contains($phase, 'cube')) {
            return BasicsDrillItem::TYPE_CUBE;
        }

        return null;
    }

    private function ensureRetryItems(BasicsDrillSession $session): void
    {
        $factType = $this->factTypeForPhase($session->phase);

        if ($session->items()->where('round', BasicsDrillItem::ROUND_RETRY)->where('fact_type', $factType)->exists()) {
            return;
        }

        $failedKeys = $session->items
            ->where('round', BasicsDrillItem::ROUND_MAIN)
            ->where('fact_type', $factType)
            ->filter(fn (BasicsDrillItem $item) => in_array($item->status, [
                BasicsDrillItem::STATUS_FAILED,
                BasicsDrillItem::STATUS_REVEALED,
            ], true))
            ->pluck('fact_key')
            ->all();

        $mainItems = $session->items
            ->where('round', BasicsDrillItem::ROUND_MAIN)
            ->where('fact_type', $factType)
            ->whereIn('fact_key', $failedKeys);

        $order = 1;
        foreach ($mainItems as $main) {
            BasicsDrillItem::query()->create([
                'basics_drill_session_id' => $session->id,
                'fact_type' => $main->fact_type,
                'fact_key' => $main->fact_key,
                'operand_a' => $main->operand_a,
                'operand_b' => $main->operand_b,
                'correct_answer' => $main->correct_answer,
                'sort_order' => 1000 + $order,
                'round' => BasicsDrillItem::ROUND_RETRY,
                'status' => BasicsDrillItem::STATUS_PENDING,
            ]);
            $order++;
        }
    }

    /**
     * @param  list<array{fact_type: string, fact_key: string, operand_a: int, operand_b: int, correct_answer: int}>  $facts
     */
    private function createItems(BasicsDrillSession $session, array $facts, string $round): void
    {
        foreach ($facts as $index => $fact) {
            BasicsDrillItem::query()->create([
                'basics_drill_session_id' => $session->id,
                'fact_type' => $fact['fact_type'],
                'fact_key' => $fact['fact_key'],
                'operand_a' => $fact['operand_a'],
                'operand_b' => $fact['operand_b'],
                'correct_answer' => $fact['correct_answer'],
                'sort_order' => $index + 1,
                'round' => $round,
                'status' => BasicsDrillItem::STATUS_PENDING,
            ]);
        }
    }

    /**
     * @return list<array{fact_type: string, fact_key: string, operand_a: int, operand_b: int, correct_answer: int}>
     */
    private function tableFacts(?int $tableNumber, array $settings): array
    {
        if ($tableNumber === null) {
            return [];
        }

        $multipliers = range(
            (int) $settings['multiplier_from'],
            (int) $settings['multiplier_to'],
        );
        shuffle($multipliers);

        return array_map(function (int $multiplier) use ($tableNumber) {
            return [
                'fact_type' => BasicsDrillItem::TYPE_TABLE,
                'fact_key' => "{$tableNumber}x{$multiplier}",
                'operand_a' => $tableNumber,
                'operand_b' => $multiplier,
                'correct_answer' => $tableNumber * $multiplier,
            ];
        }, $multipliers);
    }

    /**
     * @return list<array{fact_type: string, fact_key: string, operand_a: int, operand_b: int, correct_answer: int}>
     */
    private function squareFacts(?int $batchStart, array $settings): array
    {
        $numbers = $this->squareBatchNumbers($batchStart, $settings);
        shuffle($numbers);

        return array_map(function (int $n) {
            return [
                'fact_type' => BasicsDrillItem::TYPE_SQUARE,
                'fact_key' => "sq{$n}",
                'operand_a' => $n,
                'operand_b' => 0,
                'correct_answer' => $n * $n,
            ];
        }, $numbers);
    }

    /**
     * @return list<array{fact_type: string, fact_key: string, operand_a: int, operand_b: int, correct_answer: int}>
     */
    private function cubeFacts(?int $batchStart, array $settings): array
    {
        $numbers = $this->cubeBatchNumbers($batchStart, $settings);
        shuffle($numbers);

        return array_map(function (int $n) {
            return [
                'fact_type' => BasicsDrillItem::TYPE_CUBE,
                'fact_key' => "cb{$n}",
                'operand_a' => $n,
                'operand_b' => 0,
                'correct_answer' => $n * $n * $n,
            ];
        }, $numbers);
    }

    /**
     * @return list<int>
     */
    private function squareBatchNumbers(?int $batchStart, array $settings): array
    {
        if ($batchStart === null) {
            return [];
        }

        $count = (int) ($settings['squares_per_day'] ?? 5);
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $batchStart + $i;
            if ($n > ($settings['square_to'] ?? 30) || $n < ($settings['square_from'] ?? 2)) {
                break;
            }
            $numbers[] = $n;
        }

        return $numbers;
    }

    /**
     * @return list<int>
     */
    private function cubeBatchNumbers(?int $batchStart, array $settings): array
    {
        if ($batchStart === null) {
            return [];
        }

        $count = (int) ($settings['cubes_per_day'] ?? 3);
        $numbers = [];
        for ($i = 0; $i < $count; $i++) {
            $n = $batchStart + $i;
            if ($n > ($settings['cube_to'] ?? 13) || $n < ($settings['cube_from'] ?? 2)) {
                break;
            }
            $numbers[] = $n;
        }

        return $numbers;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chartForPhase(BasicsDrillSession $session, array $settings): ?array
    {
        if (str_contains($session->phase, 'table')) {
            $n = $session->table_number;
            if ($n === null) {
                return null;
            }

            $rows = [];
            for ($m = (int) $settings['multiplier_from']; $m <= (int) $settings['multiplier_to']; $m++) {
                $rows[] = [
                    'label' => "{$n} × {$m}",
                    'answer' => $n * $m,
                ];
            }

            return ['title' => "Table of {$n}", 'rows' => $rows];
        }

        if (str_contains($session->phase, 'square')) {
            $numbers = $this->squareBatchNumbers($session->square_batch_start, $settings);

            return [
                'title' => 'Squares',
                'rows' => array_map(fn (int $n) => [
                    'label' => "{$n}²",
                    'answer' => $n * $n,
                ], $numbers),
            ];
        }

        if (str_contains($session->phase, 'cube')) {
            $numbers = $this->cubeBatchNumbers($session->cube_batch_start, $settings);

            return [
                'title' => 'Cubes',
                'rows' => array_map(fn (int $n) => [
                    'label' => "{$n}³",
                    'answer' => $n * $n * $n,
                ], $numbers),
            ];
        }

        return null;
    }

    private function currentItem(BasicsDrillSession $session): ?BasicsDrillItem
    {
        if ($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
            return $this->nextPendingCorrectionItem($session);
        }

        if (str_ends_with($session->phase, '_show') || $session->isComplete()) {
            return null;
        }

        $factType = $this->factTypeForPhase($session->phase);
        $round = str_ends_with($session->phase, '_retry')
            ? BasicsDrillItem::ROUND_RETRY
            : BasicsDrillItem::ROUND_MAIN;

        return $session->items
            ->where('fact_type', $factType)
            ->where('round', $round)
            ->firstWhere('status', BasicsDrillItem::STATUS_PENDING);
    }

    private function nextPendingItem(BasicsDrillSession $session): ?BasicsDrillItem
    {
        return $this->currentItem($session);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatItem(BasicsDrillItem $item): array
    {
        $payload = [
            'id' => $item->id,
            'fact_type' => $item->fact_type,
            'prompt' => $item->promptLabel(),
            'round' => $item->round,
            'is_formula_mcq' => $item->isFormulaMcq(),
            'is_formula_fill_blank' => $item->isFormulaFillBlank(),
        ];

        if ($item->isFormulaMcq() || $item->isFormulaFillBlank()) {
            $item->loadMissing('question.options', 'question.blankAnswer');
            $question = $item->question;

            $payload['question'] = [
                'id' => $question->id,
                'type' => $question->type,
                'question_text' => $question->question_text,
                'explanation' => $question->explanation,
            ];

            if ($item->isFormulaFillBlank()) {
                $payload['question']['answer_format'] = $question->blankAnswer?->answer_format;
                $payload['question']['answer_format_label'] = $this->answerValidation->formatLabel($question->blankAnswer?->answer_format);
            } else {
                $payload['question']['options'] = $question->options->map(fn ($option, $index) => [
                    'id' => $option->id,
                    'letter' => chr(65 + $index),
                    'option_text' => $option->option_text,
                ])->values()->all();
            }
        }

        return $payload;
    }

    private function progressLabel(BasicsDrillSession $session): string
    {
        if ($session->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
            $items = $session->items->where('round', BasicsDrillItem::ROUND_CORRECTION);
            $done = $items->where('status', BasicsDrillItem::STATUS_CORRECT)->count();
            $total = $items->count();

            return $total > 0 ? "Correction {$done}/{$total}" : '';
        }

        $factType = $this->factTypeForPhase($session->phase);
        $round = str_ends_with($session->phase, '_retry')
            ? BasicsDrillItem::ROUND_RETRY
            : BasicsDrillItem::ROUND_MAIN;
        $items = $session->items
            ->where('fact_type', $factType)
            ->where('round', $round);
        $done = $items->whereIn('status', [
            BasicsDrillItem::STATUS_CORRECT,
            BasicsDrillItem::STATUS_REVEALED,
        ])->count();
        $total = $items->count();

        return $total > 0 ? "{$done}/{$total}" : '';
    }

    private function progressFor(Student $student): BasicsDrillProgress
    {
        $defaults = $this->settingsService->defaults();

        return BasicsDrillProgress::query()->firstOrCreate(
            ['student_id' => $student->id],
            [
                'student_enrollment_id' => $student->currentEnrollment()?->id,
                'next_table' => $defaults['table_from'] ?? 2,
                'square_batch_start' => $defaults['square_from'] ?? 2,
                'cube_batch_start' => $defaults['cube_from'] ?? 2,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function resolveTableNumber(BasicsDrillProgress $progress, array $settings): ?int
    {
        if (! ($settings['tables_enabled'] ?? false)) {
            return null;
        }

        $resolved = $this->settingsService->firstAllowedAtOrAfter(
            (int) $progress->next_table,
            $settings,
        );

        if ($resolved === null) {
            return null;
        }

        if ($resolved !== (int) $progress->next_table) {
            $progress->update(['next_table' => $resolved]);
        }

        return $resolved;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function clampSquareBatchStart(int $start, array $settings): int
    {
        $min = $settings['square_from'] ?? 2;
        $max = ($settings['square_to'] ?? 30) - ($settings['squares_per_day'] ?? 5) + 1;

        return max($min, min($start, max($min, $max)));
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function clampCubeBatchStart(int $start, array $settings): int
    {
        $min = $settings['cube_from'] ?? 2;
        $max = ($settings['cube_to'] ?? 13) - ($settings['cubes_per_day'] ?? 3) + 1;

        return max($min, min($start, max($min, $max)));
    }

    private function recordStat(int $studentId, BasicsDrillItem $item, bool $isCorrect): void
    {
        $stat = BasicsFactStat::query()->firstOrCreate(
            [
                'student_id' => $studentId,
                'fact_type' => $item->fact_type,
                'fact_key' => $item->fact_key,
            ],
            [
                'times_shown' => 0,
                'times_correct' => 0,
                'times_failed' => 0,
                'needs_review' => false,
            ],
        );

        $stat->increment('times_shown');
        $stat->update(['last_shown_date' => $this->todayDate()]);

        if ($isCorrect) {
            $stat->increment('times_correct');
            if ($stat->times_failed === 0) {
                $stat->update(['needs_review' => false]);
            }
        } else {
            $stat->increment('times_failed');
            $stat->update(['needs_review' => true]);
        }
    }
}
