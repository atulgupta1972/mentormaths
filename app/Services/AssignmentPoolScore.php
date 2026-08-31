<?php

namespace App\Services;

use App\Models\AssignmentSumInstance;
use App\Models\GuidedAttemptQuestion;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use Illuminate\Support\Facades\DB;

/**
 * Total Pool scoring for a worksheet assignment.
 *
 * Total Pool = original sums + all remedial instances spawned from wrongs.
 * Correct = first evaluation of that instance only (never retroactively fixed).
 * Completion% = attempted / pool; Score% = correct / pool.
 *
 * Learning is "fully corrected" when pending = 0 and completion = 100%
 * (Score% may stay below 100 after wrongs — originals are never retroactively fixed).
 */
class AssignmentPoolScore
{
    /**
     * Ensure one pending original instance per worksheet question for this assignment.
     */
    public function ensureOriginals(SetAssignment $assignment): void
    {
        $assignment->loadMissing([
            'enrollment:id,student_id',
            'practiceSet.questions:id',
        ]);

        $studentId = (int) ($assignment->enrollment?->student_id ?? 0);
        $worksheetId = (int) $assignment->worksheet_id;

        if ($studentId <= 0 || ! $assignment->practiceSet) {
            return;
        }

        $questionIds = $assignment->practiceSet->questions->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($questionIds === []) {
            return;
        }

        $existing = AssignmentSumInstance::query()
            ->where('set_assignment_id', $assignment->id)
            ->whereNull('source_instance_id')
            ->where('generation', 0)
            ->pluck('question_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $existingLookup = array_fill_keys($existing, true);
        $now = now();
        $rows = [];

        foreach ($questionIds as $questionId) {
            if (isset($existingLookup[$questionId])) {
                continue;
            }

            $rows[] = [
                'set_assignment_id' => $assignment->id,
                'student_id' => $studentId,
                'worksheet_id' => $worksheetId,
                'question_id' => $questionId,
                'source_instance_id' => null,
                'generation' => 0,
                'status' => AssignmentSumInstance::STATUS_PENDING,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            AssignmentSumInstance::query()->insert($rows);
        }
    }

    /**
     * Rebuild the pool from all submitted attempts (idempotent source of truth).
     * Fixes historical attempts that finished before pool scoring existed,
     * and prevents correction-only sync from marking the wrong instances.
     */
    public function rebuildFromAttempts(SetAssignment $assignment): array
    {
        $assignment->loadMissing([
            'enrollment:id,student_id',
            'practiceSet.questions:id',
        ]);

        if (! $assignment->practiceSet) {
            return $this->emptyMetrics();
        }

        $attempts = SetAttempt::query()
            ->where('set_assignment_id', $assignment->id)
            ->where('status', SetAttempt::STATUS_SUBMITTED)
            ->orderBy('attempt_number')
            ->orderBy('id')
            ->with(['guidedQuestions', 'answers'])
            ->get();

        DB::transaction(function () use ($assignment, $attempts) {
            AssignmentSumInstance::query()
                ->where('set_assignment_id', $assignment->id)
                ->where('generation', '>', 0)
                ->delete();
            AssignmentSumInstance::query()
                ->where('set_assignment_id', $assignment->id)
                ->delete();

            $this->ensureOriginals($assignment);

            foreach ($attempts as $attempt) {
                $this->applySubmittedAttempt($attempt, $assignment);
            }
        });

        return $this->metricsForAssignment($assignment);
    }

    /**
     * Apply guided attempt outcomes — rebuilds whole pool from all submitted attempts.
     */
    public function syncFromGuidedAttempt(SetAttempt $attempt): void
    {
        $attempt->loadMissing('assignment');
        if ($attempt->assignment) {
            $this->rebuildFromAttempts($attempt->assignment);
        }
    }

    /**
     * Apply batch outcomes — rebuilds whole pool from all submitted attempts.
     */
    public function syncFromBatchAttempt(SetAttempt $attempt): void
    {
        $attempt->loadMissing('assignment');
        if ($attempt->assignment) {
            $this->rebuildFromAttempts($attempt->assignment);
        }
    }

    /**
     * True when every sum instance is evaluated and no remediations remain open.
     * Score% may be &lt; 100 if earlier wrongs permanently count against the pool.
     */
    public function isFullyCorrected(SetAssignment $assignment): bool
    {
        $metrics = $this->metricsForAssignment($assignment);

        return ($metrics['pool'] ?? 0) > 0
            && ($metrics['pending'] ?? 0) === 0
            && ($metrics['completion_pct'] ?? null) === 100;
    }

    /**
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
     *     pending_remedial: int,
     *     wrong: int,
     *     completion_pct: int|null,
     *     score_pct: int|null
     * }
     */
    public function metricsForAssignment(SetAssignment $assignment): array
    {
        $counts = AssignmentSumInstance::query()
            ->where('set_assignment_id', $assignment->id)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $pending = (int) ($counts[AssignmentSumInstance::STATUS_PENDING] ?? 0);
        $correct = (int) ($counts[AssignmentSumInstance::STATUS_CORRECT] ?? 0);
        $wrong = (int) ($counts[AssignmentSumInstance::STATUS_WRONG] ?? 0);
        $pool = $pending + $correct + $wrong;
        $attempted = $correct + $wrong;

        $pendingRemedial = (int) AssignmentSumInstance::query()
            ->where('set_assignment_id', $assignment->id)
            ->where('status', AssignmentSumInstance::STATUS_PENDING)
            ->where('generation', '>', 0)
            ->count();

        if ($pool === 0) {
            return $this->emptyMetrics();
        }

        return [
            'pool' => $pool,
            'attempted' => $attempted,
            'correct' => $correct,
            'pending' => $pending,
            'pending_remedial' => $pendingRemedial,
            'wrong' => $wrong,
            'completion_pct' => (int) round(($attempted / $pool) * 100),
            'score_pct' => $attempted > 0
                ? (int) round(($correct / $attempted) * 100)
                : 0,
        ];
    }

    /**
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
     *     pending_remedial: int,
     *     wrong: int,
     *     completion_pct: int|null,
     *     score_pct: int|null
     * }|null
     */
    public function metricsForAssignmentId(?int $assignmentId): ?array
    {
        if (! $assignmentId) {
            return null;
        }

        $assignment = SetAssignment::query()->find($assignmentId);

        return $assignment ? $this->metricsForAssignment($assignment) : null;
    }

    private function applySubmittedAttempt(SetAttempt $attempt, SetAssignment $assignment): void
    {
        $preferRemedial = (bool) $attempt->is_correction_practice;

        if ($attempt->guidedQuestions->isNotEmpty()) {
            foreach ($attempt->guidedQuestions as $row) {
                if ($row->reported_issue || $row->phase === GuidedAttemptQuestion::PHASE_REPORTED_ISSUE) {
                    continue;
                }

                if ($row->phase === GuidedAttemptQuestion::PHASE_PENDING) {
                    continue;
                }

                // Prefer first-try outcome; older rows may only have final + help flags.
                $firstTryCorrect = $row->first_try_correct;
                if ($firstTryCorrect === null) {
                    if ($row->corrected_after_help || $row->gave_up) {
                        $firstTryCorrect = false;
                    } else {
                        $firstTryCorrect = (bool) $row->final_is_correct;
                    }
                }

                $this->evaluateQuestionInstance(
                    $assignment,
                    (int) $row->question_id,
                    (bool) $firstTryCorrect,
                    $attempt,
                    (int) $row->id,
                    preferPendingRemedial: $preferRemedial,
                );
            }

            return;
        }

        $answersByQuestion = $attempt->answers->keyBy('question_id');
        foreach ($assignment->practiceSet->questions as $question) {
            /** @var SetAttemptAnswer|null $answer */
            $answer = $answersByQuestion->get($question->id);
            if (! $answer) {
                continue;
            }

            $this->evaluateQuestionInstance(
                $assignment,
                (int) $question->id,
                (bool) $answer->is_correct,
                $attempt,
                null,
                preferPendingRemedial: $preferRemedial,
            );
        }
    }

    private function evaluateQuestionInstance(
        SetAssignment $assignment,
        int $questionId,
        bool $firstTryCorrect,
        SetAttempt $attempt,
        ?int $guidedAttemptQuestionId,
        bool $preferPendingRemedial,
    ): void {
        $instance = $this->resolveInstanceToEvaluate(
            $assignment,
            $questionId,
            $preferPendingRemedial,
        );

        if (! $instance || ! $instance->isPending()) {
            return;
        }

        if ($firstTryCorrect) {
            $instance->update([
                'status' => AssignmentSumInstance::STATUS_CORRECT,
                'set_attempt_id' => $attempt->id,
                'guided_attempt_question_id' => $guidedAttemptQuestionId,
                'evaluated_at' => now(),
            ]);

            return;
        }

        $instance->update([
            'status' => AssignmentSumInstance::STATUS_WRONG,
            'set_attempt_id' => $attempt->id,
            'guided_attempt_question_id' => $guidedAttemptQuestionId,
            'evaluated_at' => now(),
        ]);

        $this->spawnRemedial($instance);
    }

    private function resolveInstanceToEvaluate(
        SetAssignment $assignment,
        int $questionId,
        bool $preferPendingRemedial,
    ): ?AssignmentSumInstance {
        if ($preferPendingRemedial) {
            $remedial = AssignmentSumInstance::query()
                ->where('set_assignment_id', $assignment->id)
                ->where('question_id', $questionId)
                ->where('status', AssignmentSumInstance::STATUS_PENDING)
                ->where('generation', '>', 0)
                ->orderBy('generation')
                ->orderBy('id')
                ->first();

            if ($remedial) {
                return $remedial;
            }
        }

        return AssignmentSumInstance::query()
            ->where('set_assignment_id', $assignment->id)
            ->where('question_id', $questionId)
            ->where('status', AssignmentSumInstance::STATUS_PENDING)
            ->orderBy('generation')
            ->orderBy('id')
            ->first();
    }

    private function spawnRemedial(AssignmentSumInstance $wrong): AssignmentSumInstance
    {
        $existing = AssignmentSumInstance::query()
            ->where('source_instance_id', $wrong->id)
            ->where('status', AssignmentSumInstance::STATUS_PENDING)
            ->first();

        if ($existing) {
            return $existing;
        }

        return AssignmentSumInstance::query()->create([
            'set_assignment_id' => $wrong->set_assignment_id,
            'student_id' => $wrong->student_id,
            'worksheet_id' => $wrong->worksheet_id,
            'question_id' => $wrong->question_id,
            'source_instance_id' => $wrong->id,
            'generation' => ((int) $wrong->generation) + 1,
            'status' => AssignmentSumInstance::STATUS_PENDING,
        ]);
    }

    /**
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
     *     pending_remedial: int,
     *     wrong: int,
     *     completion_pct: int|null,
     *     score_pct: int|null
     * }
     */
    private function emptyMetrics(): array
    {
        return [
            'pool' => 0,
            'attempted' => 0,
            'correct' => 0,
            'pending' => 0,
            'pending_remedial' => 0,
            'wrong' => 0,
            'completion_pct' => null,
            'score_pct' => null,
        ];
    }
}
