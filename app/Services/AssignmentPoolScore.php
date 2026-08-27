<?php

namespace App\Services;

use App\Models\AssignmentSumInstance;
use App\Models\GuidedAttemptQuestion;
use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Total Pool scoring for a worksheet assignment.
 *
 * Total Pool = original sums + all remedial instances spawned from wrongs.
 * Correct = first evaluation of that instance only (never retroactively fixed).
 * Completion% = attempted / pool; Score% = correct / pool.
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
     * Apply guided attempt outcomes to the pool (first-try only) and spawn remediations for wrongs.
     */
    public function syncFromGuidedAttempt(SetAttempt $attempt): void
    {
        $attempt->loadMissing([
            'assignment.enrollment:id,student_id',
            'assignment.practiceSet.questions:id',
            'guidedQuestions',
        ]);

        $assignment = $attempt->assignment;
        if (! $assignment) {
            return;
        }

        $this->ensureOriginals($assignment);

        DB::transaction(function () use ($attempt, $assignment) {
            foreach ($attempt->guidedQuestions as $row) {
                if ($row->reported_issue || $row->phase === GuidedAttemptQuestion::PHASE_REPORTED_ISSUE) {
                    continue;
                }

                if ($row->phase === GuidedAttemptQuestion::PHASE_PENDING) {
                    continue;
                }

                $firstTryCorrect = (bool) $row->first_try_correct;
                $this->evaluateQuestionInstance(
                    $assignment,
                    (int) $row->question_id,
                    $firstTryCorrect,
                    $attempt,
                    (int) $row->id,
                    preferPendingRemedial: (bool) $attempt->is_correction_practice,
                );
            }
        });
    }

    /**
     * Apply batch (chapter test) outcomes and spawn remediations for wrongs.
     */
    public function syncFromBatchAttempt(SetAttempt $attempt): void
    {
        $attempt->loadMissing([
            'assignment.enrollment:id,student_id',
            'assignment.practiceSet.questions:id',
            'answers',
        ]);

        $assignment = $attempt->assignment;
        if (! $assignment) {
            return;
        }

        $this->ensureOriginals($assignment);

        DB::transaction(function () use ($attempt, $assignment) {
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
                    preferPendingRemedial: (bool) $attempt->is_correction_practice,
                );
            }
        });
    }

    /**
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
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

        if ($pool === 0) {
            return [
                'pool' => 0,
                'attempted' => 0,
                'correct' => 0,
                'pending' => 0,
                'wrong' => 0,
                'completion_pct' => null,
                'score_pct' => null,
            ];
        }

        return [
            'pool' => $pool,
            'attempted' => $attempted,
            'correct' => $correct,
            'pending' => $pending,
            'wrong' => $wrong,
            'completion_pct' => (int) round(($attempted / $pool) * 100),
            'score_pct' => (int) round(($correct / $pool) * 100),
        ];
    }

    /**
     * @return array{
     *     pool: int,
     *     attempted: int,
     *     correct: int,
     *     pending: int,
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
}
