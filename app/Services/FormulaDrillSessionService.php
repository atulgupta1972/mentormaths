<?php

namespace App\Services;

use App\Models\FormulaDrillItem;
use App\Models\FormulaDrillSession;
use App\Models\FormulaQuestionStat;
use App\Models\Question;
use App\Models\Student;
use Carbon\Carbon;

class FormulaDrillSessionService
{
    public function __construct(
        private FormulaDrillPoolService $poolService,
        private PracticeCorrectionQueueService $correctionQueue,
    ) {}

    public function todayDate(): Carbon
    {
        return now(config('formula_drill.timezone', 'Asia/Kolkata'))->startOfDay();
    }

    public function todaysSession(Student $student): ?FormulaDrillSession
    {
        return FormulaDrillSession::query()
            ->where('student_id', $student->id)
            ->whereDate('drill_date', $this->todayDate())
            ->with(['items.question.options'])
            ->first();
    }

    public function gatePassed(Student $student): bool
    {
        $session = $this->todaysSession($student);

        if (! $session) {
            return false;
        }

        if ($session->status === FormulaDrillSession::STATUS_SKIPPED) {
            return $this->poolService->poolSize($student) === 0;
        }

        return $session->isComplete();
    }

    public function getOrCreateTodaysSession(Student $student): FormulaDrillSession
    {
        $existing = $this->todaysSession($student);

        if ($existing) {
            if ($existing->status === FormulaDrillSession::STATUS_SKIPPED
                && $this->poolService->poolSize($student) > 0) {
                $existing->delete();
            } else {
                return $existing;
            }
        }

        $poolIds = $this->poolService->poolQuestionIds($student);
        $dailyCount = (int) config('formula_drill.daily_question_count', 10);
        $correctionCount = (int) config('formula_drill.daily_correction_count', 5);

        $correctionItems = $this->correctionQueue->selectForDailyDrill($student, $correctionCount);
        $correctionQuestionIds = $correctionItems->pluck('question_id')->all();
        $totalQuestions = count($poolIds) + count($correctionQuestionIds);

        if ($totalQuestions === 0) {
            return FormulaDrillSession::query()->create([
                'student_id' => $student->id,
                'student_enrollment_id' => $student->currentEnrollment()?->id,
                'drill_date' => $this->todayDate(),
                'status' => FormulaDrillSession::STATUS_SKIPPED,
                'questions_total' => 0,
                'questions_completed' => 0,
                'pool_size' => 0,
                'completed_at' => now(),
            ]);
        }

        $selectedIds = $this->selectQuestionIds($student, $poolIds, $dailyCount);
        $allQuestionRows = [];

        foreach (array_values($selectedIds) as $questionId) {
            $allQuestionRows[] = [
                'question_id' => $questionId,
                'practice_correction_item_id' => null,
            ];
        }

        foreach ($correctionItems as $correctionItem) {
            if (in_array($correctionItem->question_id, $selectedIds, true)) {
                continue;
            }

            $allQuestionRows[] = [
                'question_id' => $correctionItem->question_id,
                'practice_correction_item_id' => $correctionItem->id,
            ];
        }

        $session = FormulaDrillSession::query()->create([
            'student_id' => $student->id,
            'student_enrollment_id' => $student->currentEnrollment()?->id,
            'drill_date' => $this->todayDate(),
            'status' => FormulaDrillSession::STATUS_IN_PROGRESS,
            'questions_total' => count($allQuestionRows),
            'questions_completed' => 0,
            'pool_size' => count($poolIds),
        ]);

        foreach (array_values($allQuestionRows) as $index => $row) {
            FormulaDrillItem::query()->create([
                'formula_drill_session_id' => $session->id,
                'question_id' => $row['question_id'],
                'practice_correction_item_id' => $row['practice_correction_item_id'],
                'sort_order' => $index + 1,
                'round' => FormulaDrillItem::ROUND_MAIN,
                'status' => FormulaDrillItem::STATUS_PENDING,
            ]);

            $this->markQuestionShown($student->id, $row['question_id']);
        }

        return $session->load(['items.question.options']);
    }

    /**
     * @param  list<int>  $poolIds
     * @return list<int>
     */
    private function selectQuestionIds(Student $student, array $poolIds, int $limit): array
    {
        if ($poolIds === []) {
            return [];
        }

        $reviewIds = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->where('needs_review', true)
            ->whereIn('question_id', $poolIds)
            ->orderByDesc('total_failures')
            ->orderBy('last_shown_date')
            ->pluck('question_id')
            ->all();

        $selected = array_slice($reviewIds, 0, $limit);
        $remaining = $limit - count($selected);

        if ($remaining <= 0) {
            return $selected;
        }

        $alreadySelected = collect($selected);

        $shownQuestionIds = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->whereIn('question_id', $poolIds)
            ->where('times_shown', '>', 0)
            ->pluck('question_id')
            ->all();

        $neverShownIds = collect($poolIds)
            ->diff($alreadySelected)
            ->diff($shownQuestionIds)
            ->shuffle()
            ->values();

        foreach ($neverShownIds as $questionId) {
            if (count($selected) >= $limit) {
                return $selected;
            }

            $selected[] = $questionId;
        }

        if (count($selected) >= $limit) {
            return $selected;
        }

        $leastRecentlyShown = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->whereIn('question_id', $poolIds)
            ->orderBy('last_shown_date')
            ->orderBy('times_shown')
            ->pluck('question_id');

        $repeatCandidates = $leastRecentlyShown
            ->merge(collect($poolIds)->shuffle())
            ->unique()
            ->reject(fn (int $id) => collect($selected)->contains($id))
            ->values();

        foreach ($repeatCandidates as $questionId) {
            if (count($selected) >= $limit) {
                break;
            }

            $selected[] = $questionId;
        }

        return $selected;
    }

    public function currentItem(FormulaDrillSession $session): ?FormulaDrillItem
    {
        return $session->items
            ->first(fn (FormulaDrillItem $item) => ! $item->isDone());
    }

    /**
     * @return array{correct: bool, failed: bool, correct_option_id: ?int, session_complete: bool}
     */
    public function submitAnswer(FormulaDrillSession $session, FormulaDrillItem $item, int $optionId): array
    {
        if ($item->formula_drill_session_id !== $session->id || $item->isDone()) {
            throw new \InvalidArgumentException('This formula question is already completed.');
        }

        $question = $item->question ?? Question::query()->with('options')->findOrFail($item->question_id);
        $option = $question->options->firstWhere('id', $optionId);

        if (! $option) {
            throw new \InvalidArgumentException('Invalid option selected.');
        }

        $item->attempt_count++;
        $isCorrect = (bool) $option->is_correct;

        if (! $isCorrect) {
            $item->failure_count++;
        }

        $stat = FormulaQuestionStat::query()->firstOrCreate(
            [
                'student_id' => $session->student_id,
                'question_id' => $question->id,
            ],
            [
                'total_failures' => 0,
                'times_shown' => 0,
                'times_correct' => 0,
                'times_exhausted' => 0,
                'needs_review' => false,
            ],
        );

        $correctOptionId = $question->options->firstWhere('is_correct', true)?->id;

        if ($isCorrect) {
            $item->status = FormulaDrillItem::STATUS_CORRECT;
            $item->completed_at = now();
            $item->save();

            $stat->times_correct++;
            $stat->needs_review = false;
            $stat->last_correct_at = now();
            $stat->save();

            return $this->advanceSession($session, true, false, $correctOptionId);
        }

        $item->status = FormulaDrillItem::STATUS_FAILED;
        $item->completed_at = now();
        $item->save();

        $stat->total_failures++;
        $stat->needs_review = true;
        $stat->save();

        return $this->advanceSession($session, false, true, $correctOptionId);
    }

    /**
     * @return array{correct: bool, failed: bool, correct_option_id: ?int, session_complete: bool}
     */
    private function advanceSession(
        FormulaDrillSession $session,
        bool $correct,
        bool $failed,
        ?int $correctOptionId,
    ): array {
        $session->increment('questions_completed');
        $session->refresh();
        $session->load('items');

        if ($session->questions_completed >= $session->questions_total) {
            $session->update([
                'status' => FormulaDrillSession::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return [
                'correct' => $correct,
                'failed' => $failed,
                'correct_option_id' => $failed ? $correctOptionId : null,
                'session_complete' => true,
            ];
        }

        return [
            'correct' => $correct,
            'failed' => $failed,
            'correct_option_id' => $failed ? $correctOptionId : null,
            'session_complete' => false,
        ];
    }

    private function markQuestionShown(int $studentId, int $questionId): void
    {
        $stat = FormulaQuestionStat::query()->firstOrCreate(
            [
                'student_id' => $studentId,
                'question_id' => $questionId,
            ],
            [
                'total_failures' => 0,
                'times_shown' => 0,
                'times_correct' => 0,
                'times_exhausted' => 0,
                'needs_review' => false,
            ],
        );

        $today = $this->todayDate()->toDateString();

        if ($stat->last_shown_date?->toDateString() !== $today) {
            $stat->times_shown++;
            $stat->last_shown_date = $this->todayDate();
            $stat->save();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionPayload(FormulaDrillSession $session): array
    {
        $current = $this->currentItem($session);

        $student = $session->student ?? Student::query()->find($session->student_id);

        return [
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'drill_date' => $session->drill_date->toDateString(),
                'questions_total' => $session->questions_total,
                'questions_completed' => $session->questions_completed,
                'pool_size' => $session->pool_size,
                'is_complete' => $session->isComplete(),
            ],
            'pool_breakdown' => $student
                ? $this->poolService->poolBreakdown($student)
                : null,
            'current' => $current ? $this->itemPayload($current) : null,
            'progress_label' => $session->questions_total > 0
                ? ($session->questions_completed + ($current ? 1 : 0)).' / '.$session->questions_total
                : '0 / 0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function itemPayload(FormulaDrillItem $item): array
    {
        $question = $item->question;

        return [
            'id' => $item->id,
            'sort_order' => $item->sort_order,
            'attempt_count' => $item->attempt_count,
            'is_practice_correction' => $item->practice_correction_item_id !== null,
            'question' => [
                'id' => $question->id,
                'question_text' => $question->question_text,
                'explanation' => $question->explanation,
                'options' => $question->options->map(fn ($option, $index) => [
                    'id' => $option->id,
                    'letter' => chr(65 + $index),
                    'option_text' => $option->option_text,
                ])->values()->all(),
            ],
        ];
    }
}
