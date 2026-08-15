<?php

namespace App\Services;

use App\Models\FormulaDrillItem;
use App\Models\FormulaDrillSession;
use App\Models\FormulaQuestionStat;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\Student;
use App\Support\AnswerValidationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FormulaDrillSessionService
{
    public function __construct(
        private FormulaDrillPoolService $poolService,
        private PracticeCorrectionQueueService $correctionQueue,
        private AnswerValidationService $answerValidation,
        private QuestionResolutionService $resolutionService,
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
            ->with(['items.question.options', 'items.question.blankAnswer'])
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

        $dailyCount = (int) config('formula_drill.daily_question_count', 5);
        $correctionCount = (int) config('formula_drill.daily_correction_count', 5);
        $plannedMax = $dailyCount + $correctionCount;

        if ($existing) {
            if ($existing->status === FormulaDrillSession::STATUS_SKIPPED
                && $this->poolService->poolSize($student) > 0) {
                $existing->delete();
            } elseif ($existing->status === FormulaDrillSession::STATUS_IN_PROGRESS
                && $existing->questions_total > $plannedMax) {
                $existing->delete();
            } else {
                return $existing;
            }
        }

        $poolIds = $this->poolService->poolQuestionIds($student);

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

        return $session->load(['items.question.options', 'items.question.blankAnswer']);
    }

    /**
     * Pick up to $limit formula questions with no repeats until every pool formula
     * has been shown at least once. Review retries only start after that full pass.
     *
     * @param  list<int>  $poolIds
     * @return list<int>
     */
    private function selectQuestionIds(Student $student, array $poolIds, int $limit): array
    {
        if ($poolIds === [] || $limit <= 0) {
            return [];
        }

        $shownQuestionIds = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->whereIn('question_id', $poolIds)
            ->where('times_shown', '>', 0)
            ->pluck('question_id')
            ->all();

        $neverShownIds = collect($poolIds)
            ->diff($shownQuestionIds)
            ->shuffle()
            ->values()
            ->all();

        $selected = array_slice($neverShownIds, 0, $limit);

        if (count($selected) >= $limit) {
            return $selected;
        }

        // Full pool has been shown at least once — now allow carefully ordered repeats.
        $selectedLookup = collect($selected);

        $reviewIds = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->where('needs_review', true)
            ->whereIn('question_id', $poolIds)
            ->where('times_shown', '>', 0)
            ->orderByDesc('total_failures')
            ->orderBy('last_shown_date')
            ->pluck('question_id')
            ->reject(fn (int $id) => $selectedLookup->contains($id))
            ->values()
            ->all();

        foreach ($reviewIds as $questionId) {
            if (count($selected) >= $limit) {
                return $selected;
            }

            $selected[] = $questionId;
            $selectedLookup->push($questionId);
        }

        if (count($selected) >= $limit) {
            return $selected;
        }

        $leastRecentlyShown = FormulaQuestionStat::query()
            ->where('student_id', $student->id)
            ->whereIn('question_id', $poolIds)
            ->where('times_shown', '>', 0)
            ->orderBy('last_shown_date')
            ->orderBy('times_shown')
            ->pluck('question_id');

        $repeatCandidates = $leastRecentlyShown
            ->merge(collect($poolIds)->shuffle())
            ->unique()
            ->reject(fn (int $id) => $selectedLookup->contains($id))
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
     * @return array{correct: bool, exhausted: bool, attempts_left: int, correct_option_id: ?int, correct_answer: ?string, session_complete: bool}
     */
    public function submitAnswer(FormulaDrillSession $session, FormulaDrillItem $item, ?int $optionId = null, ?string $answerText = null): array
    {
        $maxAttempts = (int) config('formula_drill.max_attempts_per_question', 4);

        if ($item->formula_drill_session_id !== $session->id || $item->isDone()) {
            throw new \InvalidArgumentException('This formula question is already completed.');
        }

        $question = $item->question ?? Question::query()->with(['options', 'blankAnswer'])->findOrFail($item->question_id);

        if ($question->isFillInBlank()) {
            return $this->submitFillBlankAnswer($session, $item, $question, trim((string) ($answerText ?? '')), $maxAttempts);
        }

        if ($optionId === null) {
            throw new \InvalidArgumentException('Select an option before submitting.');
        }

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

        if ($isCorrect) {
            $item->status = FormulaDrillItem::STATUS_CORRECT;
            $item->completed_at = now();
            $item->save();

            $stat->times_correct++;
            $stat->needs_review = $item->failure_count > 0;
            $stat->last_correct_at = now();
            $stat->save();

            $this->markPracticeCorrectionIfFirstTry($session, $item);

            return $this->advanceSession($session, true, false, $maxAttempts, $optionId);
        }

        if ($item->attempt_count >= $maxAttempts) {
            $item->status = FormulaDrillItem::STATUS_EXHAUSTED;
            $item->completed_at = now();
            $item->save();

            $stat->times_exhausted++;
            $stat->needs_review = true;
            $stat->save();

            $correctOptionId = $question->options->firstWhere('is_correct', true)?->id;

            return $this->advanceSession($session, false, true, 0, $correctOptionId);
        }

        $item->save();
        $stat->total_failures++;
        $stat->save();

        return [
            'correct' => false,
            'exhausted' => false,
            'attempts_left' => $maxAttempts - $item->attempt_count,
            'correct_option_id' => null,
            'correct_answer' => null,
            'session_complete' => false,
        ];
    }

    /**
     * @return array{correct: bool, exhausted: bool, attempts_left: int, correct_option_id: ?int, correct_answer: ?string, session_complete: bool}
     */
    private function submitFillBlankAnswer(
        FormulaDrillSession $session,
        FormulaDrillItem $item,
        Question $question,
        string $answerText,
        int $maxAttempts,
    ): array {
        if ($answerText === '') {
            throw new \InvalidArgumentException('Enter an answer before submitting.');
        }

        $item->attempt_count++;
        $isCorrect = $this->answerValidation->isCorrect($question, $answerText);

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

        if ($isCorrect) {
            $item->status = FormulaDrillItem::STATUS_CORRECT;
            $item->completed_at = now();
            $item->save();

            $stat->times_correct++;
            $stat->needs_review = $item->failure_count > 0;
            $stat->last_correct_at = now();
            $stat->save();

            $this->markPracticeCorrectionIfFirstTry($session, $item);

            return $this->advanceSession($session, true, false, $maxAttempts - $item->attempt_count, null);
        }

        if ($item->attempt_count >= $maxAttempts) {
            $item->status = FormulaDrillItem::STATUS_EXHAUSTED;
            $item->completed_at = now();
            $item->save();

            $stat->times_exhausted++;
            $stat->needs_review = true;
            $stat->save();

            $question->loadMissing('blankAnswer');

            return $this->advanceSession(
                $session,
                false,
                true,
                0,
                null,
                $question->blankAnswer?->correct_answer,
            );
        }

        $item->save();
        $stat->total_failures++;
        $stat->save();

        return [
            'correct' => false,
            'exhausted' => false,
            'attempts_left' => $maxAttempts - $item->attempt_count,
            'correct_option_id' => null,
            'correct_answer' => null,
            'session_complete' => false,
        ];
    }

    /**
     * @return array{help_requested: bool, session_complete: bool, session: array<string, mixed>}
     */
    public function requestTeacherHelp(FormulaDrillSession $session, FormulaDrillItem $item): array
    {
        if ($item->formula_drill_session_id !== $session->id || $item->isDone()) {
            throw new \InvalidArgumentException('This question is already completed.');
        }

        if (! $item->isPracticeCorrection()) {
            throw new \InvalidArgumentException('Teacher help is only available on revision sums, not formula recall.');
        }

        $session->loadMissing('student');
        $student = $session->student;

        if (! $student) {
            throw new \InvalidArgumentException('Student record is missing.');
        }

        $item->loadMissing('practiceCorrectionItem');
        $question = $item->question ?? Question::query()->findOrFail($item->question_id);

        return DB::transaction(function () use ($session, $item, $student, $question) {
            $this->resolutionService->queueHelpRequest(
                $student,
                $question,
                $item->practiceCorrectionItem?->set_assignment_id,
            );
            $this->correctionQueue->flagNeedsRevisionAfterTeacherHelp($student, $question);

            $item->update([
                'status' => FormulaDrillItem::STATUS_HELP_REQUESTED,
                'completed_at' => now(),
            ]);

            $session->increment('questions_completed');
            $session->refresh()->load(['items.question.options', 'items.question.blankAnswer']);

            $sessionComplete = $session->questions_completed >= $session->questions_total;

            if ($sessionComplete) {
                $session->update([
                    'status' => FormulaDrillSession::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }

            return [
                'help_requested' => true,
                'session_complete' => $sessionComplete,
                'session' => $this->sessionPayload($session),
            ];
        });
    }

    /**
     * @return array{correct: bool, exhausted: bool, attempts_left: int, correct_option_id: ?int, correct_answer: ?string, session_complete: bool}
     */
    private function advanceSession(
        FormulaDrillSession $session,
        bool $correct,
        bool $exhausted,
        int $attemptsLeft,
        ?int $correctOptionId,
        ?string $correctAnswer = null,
    ): array {
        $session->increment('questions_completed');
        $session->refresh();
        $session->load(['items.question.options', 'items.question.blankAnswer']);

        if ($session->questions_completed >= $session->questions_total) {
            $session->update([
                'status' => FormulaDrillSession::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);

            return [
                'correct' => $correct,
                'exhausted' => $exhausted,
                'attempts_left' => $attemptsLeft,
                'correct_option_id' => $exhausted ? $correctOptionId : null,
                'correct_answer' => $exhausted ? $correctAnswer : null,
                'session_complete' => true,
            ];
        }

        return [
            'correct' => $correct,
            'exhausted' => $exhausted,
            'attempts_left' => $attemptsLeft,
            'correct_option_id' => $exhausted ? $correctOptionId : null,
            'correct_answer' => $exhausted ? $correctAnswer : null,
            'session_complete' => false,
        ];
    }

    private function markPracticeCorrectionIfFirstTry(FormulaDrillSession $session, FormulaDrillItem $item): void
    {
        if (! $item->isPracticeCorrection() || $item->failure_count > 0) {
            return;
        }

        $this->correctionQueue->markCorrected(
            $session->student_id,
            $item->question_id,
            PracticeCorrectionItem::CORRECTED_IN_DAILY_DRILL,
        );
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
        $session->loadMissing('items');
        $current = $this->currentItem($session);

        $student = $session->student ?? Student::query()->find($session->student_id);
        $revisionCount = $session->items
            ->filter(fn (FormulaDrillItem $item) => $item->practice_correction_item_id !== null)
            ->count();
        $formulaCount = max(0, $session->questions_total - $revisionCount);

        return [
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'drill_date' => $session->drill_date->toDateString(),
                'questions_total' => $session->questions_total,
                'questions_completed' => $session->questions_completed,
                'formula_count' => $formulaCount,
                'revision_count' => $revisionCount,
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
        $question->loadMissing(['options', 'blankAnswer']);
        $maxAttempts = (int) config('formula_drill.max_attempts_per_question', 4);

        $questionPayload = [
            'id' => $question->id,
            'type' => $question->type,
            'question_text' => $question->question_text,
            'explanation' => $question->explanation,
        ];

        if ($question->isFillInBlank()) {
            $questionPayload['answer_format'] = $question->blankAnswer?->answer_format;
            $questionPayload['answer_format_label'] = $this->answerValidation->formatLabel($question->blankAnswer?->answer_format);
            $questionPayload['options'] = [];
        } else {
            $questionPayload['options'] = $question->options->map(fn ($option, $index) => [
                'id' => $option->id,
                'letter' => chr(65 + $index),
                'option_text' => $option->option_text,
            ])->values()->all();
        }

        return [
            'id' => $item->id,
            'sort_order' => $item->sort_order,
            'attempt_count' => $item->attempt_count,
            'attempts_left' => max(0, $maxAttempts - $item->attempt_count),
            'is_practice_correction' => $item->practice_correction_item_id !== null,
            'can_request_teacher_help' => $item->isPracticeCorrection() && ! $item->isDone(),
            'question' => $questionPayload,
        ];
    }
}
