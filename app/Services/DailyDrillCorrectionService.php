<?php

namespace App\Services;

use App\Models\BasicsDrillItem;
use App\Models\BasicsDrillSession;
use App\Models\FormulaDrillItem;
use App\Models\FormulaDrillSession;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\Student;

class DailyDrillCorrectionService
{
    public function __construct(
        private FormulaDrillSessionService $formulaService,
        private PracticeCorrectionQueueService $correctionQueue,
    ) {}

    public function needsFinalCorrection(Student $student): bool
    {
        if (! $this->formulaService->gatePassed($student)) {
            return false;
        }

        $session = BasicsDrillSession::query()
            ->where('student_id', $student->id)
            ->whereDate('drill_date', app(BasicsDrillSessionService::class)->todayDate())
            ->with('items')
            ->first();

        if ($session?->isComplete()) {
            return false;
        }

        if ($session?->phase === BasicsDrillSession::PHASE_FINAL_CORRECTION) {
            return $session->items
                ->where('round', BasicsDrillItem::ROUND_CORRECTION)
                ->contains(fn (BasicsDrillItem $item) => $item->status === BasicsDrillItem::STATUS_PENDING);
        }

        if ($session && ! $session->isComplete()) {
            return false;
        }

        return $this->hasUncorrectedFailures($student);
    }

    public function isCorrectionPending(Student $student): bool
    {
        return $this->needsFinalCorrection($student);
    }

    public function hasUncorrectedFailures(Student $student): bool
    {
        return $this->failureDescriptors($student) !== [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function failureDescriptors(Student $student): array
    {
        $failures = [];

        $formulaSession = $this->formulaService->todaysSession($student);

        if ($formulaSession && $formulaSession->status !== FormulaDrillSession::STATUS_SKIPPED) {
            foreach ($formulaSession->items as $item) {
                if (! $item->isMainRound()) {
                    continue;
                }

                if ($item->status !== FormulaDrillItem::STATUS_FAILED) {
                    continue;
                }

                $failures[] = [
                    'kind' => 'formula',
                    'formula_drill_item_id' => $item->id,
                    'question_id' => $item->question_id,
                    'practice_correction_item_id' => $item->practice_correction_item_id,
                ];
            }
        }

        $basicsSession = BasicsDrillSession::query()
            ->where('student_id', $student->id)
            ->whereDate('drill_date', app(BasicsDrillSessionService::class)->todayDate())
            ->with('items')
            ->first();

        if ($basicsSession) {
            foreach ($basicsSession->items as $item) {
                if ($item->round !== BasicsDrillItem::ROUND_MAIN) {
                    continue;
                }

                if (! in_array($item->status, [
                    BasicsDrillItem::STATUS_FAILED,
                    BasicsDrillItem::STATUS_REVEALED,
                ], true)) {
                    continue;
                }

                $failures[] = [
                    'kind' => 'basics',
                    'basics_drill_item_id' => $item->id,
                    'fact_type' => $item->fact_type,
                    'fact_key' => $item->fact_key,
                    'operand_a' => $item->operand_a,
                    'operand_b' => $item->operand_b,
                    'correct_answer' => $item->correct_answer,
                ];
            }
        }

        return $failures;
    }

    public function ensureCorrectionItems(BasicsDrillSession $session): bool
    {
        if ($session->items()->where('round', BasicsDrillItem::ROUND_CORRECTION)->exists()) {
            return true;
        }

        $session->loadMissing('student');
        $student = $session->student;

        if (! $student) {
            return false;
        }

        $failures = $this->failureDescriptors($student);

        if ($failures === []) {
            return false;
        }

        $order = 1;

        foreach ($failures as $failure) {
            BasicsDrillItem::query()->create([
                'basics_drill_session_id' => $session->id,
                'question_id' => $failure['question_id'] ?? null,
                'practice_correction_item_id' => $failure['practice_correction_item_id'] ?? null,
                'source_formula_drill_item_id' => $failure['formula_drill_item_id'] ?? null,
                'source_basics_drill_item_id' => $failure['basics_drill_item_id'] ?? null,
                'fact_type' => $failure['kind'] === 'formula'
                    ? BasicsDrillItem::TYPE_FORMULA
                    : $failure['fact_type'],
                'fact_key' => $failure['fact_key'] ?? ('formula-'.($failure['question_id'] ?? $order)),
                'operand_a' => $failure['operand_a'] ?? 0,
                'operand_b' => $failure['operand_b'] ?? 0,
                'correct_answer' => $failure['correct_answer'] ?? 0,
                'sort_order' => $order,
                'round' => BasicsDrillItem::ROUND_CORRECTION,
                'status' => BasicsDrillItem::STATUS_PENDING,
            ]);
            $order++;
        }

        return true;
    }

    /**
     * @return array{correct: bool, reveal: bool, correct_option_id?: int, prompt?: string, item_id?: int}
     */
    public function submitMcqAnswer(BasicsDrillItem $item, int $optionId): array
    {
        $question = $item->question ?? Question::query()->with('options')->findOrFail($item->question_id);
        $option = $question->options->firstWhere('id', $optionId);

        if (! $option) {
            throw new \InvalidArgumentException('Invalid option selected.');
        }

        if ((bool) $option->is_correct) {
            $item->update(['status' => BasicsDrillItem::STATUS_CORRECT]);
            $this->markSourcesCorrected($item);

            return [
                'correct' => true,
                'reveal' => false,
            ];
        }

        return [
            'correct' => false,
            'reveal' => true,
            'correct_option_id' => $question->options->firstWhere('is_correct', true)?->id,
            'prompt' => $question->question_text,
            'item_id' => $item->id,
        ];
    }

    public function markSourcesCorrected(BasicsDrillItem $item): void
    {
        $session = $item->session;

        if (! $session?->student_id) {
            return;
        }

        if ($item->practice_correction_item_id) {
            $correctionItem = PracticeCorrectionItem::query()->find($item->practice_correction_item_id);

            if ($correctionItem?->question_id) {
                $this->correctionQueue->markCorrected(
                    $session->student_id,
                    $correctionItem->question_id,
                    PracticeCorrectionItem::CORRECTED_IN_DAILY_DRILL,
                );
            }

            return;
        }

        if ($item->question_id) {
            $this->correctionQueue->markCorrected(
                $session->student_id,
                $item->question_id,
                PracticeCorrectionItem::CORRECTED_IN_DAILY_DRILL,
            );
        }
    }
}
