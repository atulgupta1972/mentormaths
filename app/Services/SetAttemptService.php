<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\StudentEnrollment;
use App\Support\AssignmentProgress;
use Illuminate\Support\Facades\DB;

class SetAttemptService
{
    public function __construct(private GuidedPracticeService $guidedPractice) {}

    public function start(SetAssignment $assignment): SetAttempt
    {
        $inProgress = $assignment->attempts()
            ->where('status', SetAttempt::STATUS_IN_PROGRESS)
            ->first();

        if ($inProgress) {
            return $inProgress;
        }

        if ($assignment->status === SetAssignment::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Ask your teacher to allow another attempt.');
        }

        $nextNumber = ($assignment->attempts()->max('attempt_number') ?? 0) + 1;

        $assignment->loadMissing('practiceSet');

        return DB::transaction(function () use ($assignment, $nextNumber) {
            $attempt = SetAttempt::create([
                'set_assignment_id' => $assignment->id,
                'attempt_number' => $nextNumber,
                'mode' => $assignment->practiceSet->isChapterScope()
                    ? SetAttempt::MODE_BATCH
                    : SetAttempt::MODE_GUIDED,
                'started_at' => now(),
                'status' => SetAttempt::STATUS_IN_PROGRESS,
            ]);

            if ($attempt->isGuided()) {
                $this->guidedPractice->initialize($attempt);
            }

            if ($assignment->status === SetAssignment::STATUS_ASSIGNED) {
                $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
            }

            return $attempt;
        });
    }

    public function submit(SetAttempt $attempt, array $answers): SetAttempt
    {
        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException('This attempt has already been submitted.');
        }

        $assignment = $attempt->assignment()->with('practiceSet.questions.options')->first();
        $questions = $assignment->practiceSet->questions->keyBy('id');

        return DB::transaction(function () use ($attempt, $answers, $assignment, $questions) {
            $score = 0;
            $maxScore = $questions->count();

            foreach ($questions as $question) {
                $selectedOptionId = $answers[$question->id] ?? null;
                $isCorrect = false;

                if ($selectedOptionId) {
                    $option = $question->options->firstWhere('id', (int) $selectedOptionId);
                    $isCorrect = $option?->is_correct ?? false;
                }

                if ($isCorrect) {
                    $score++;
                }

                SetAttemptAnswer::updateOrCreate(
                    [
                        'set_attempt_id' => $attempt->id,
                        'question_id' => $question->id,
                    ],
                    [
                        'question_option_id' => $selectedOptionId ?: null,
                        'is_correct' => $isCorrect,
                    ],
                );
            }

            $timeSeconds = (int) $attempt->started_at->diffInSeconds(now());
            $completedAt = now();
            $submissionTiming = AssignmentProgress::submissionTiming($assignment, $completedAt);

            $attempt->update([
                'completed_at' => $completedAt,
                'score' => $score,
                'max_score' => $maxScore,
                'time_seconds' => $timeSeconds,
                'status' => SetAttempt::STATUS_SUBMITTED,
                'submission_timing' => $submissionTiming,
            ]);

            $assignment->update(['status' => SetAssignment::STATUS_COMPLETED]);

            return $attempt->fresh(['answers', 'assignment.practiceSet']);
        });
    }

    public function dashboardForEnrollment(StudentEnrollment $enrollment): array
    {
        $assignments = SetAssignment::query()
            ->with([
                'practiceSet' => fn ($q) => $q->withCount('questions'),
                'attempts' => fn ($q) => $q->orderByDesc('attempt_number')->limit(1),
            ])
            ->where('student_enrollment_id', $enrollment->id)
            ->whereHas('practiceSet', fn ($q) => $q->where('status', 'published'))
            ->get();

        return $assignments->map(function (SetAssignment $assignment) {
            $latest = $assignment->attempts->first();

            return AssignmentProgress::formatStudentDashboardSummary($assignment, $latest);
        })->sortBy([
            ['set_code', 'asc'],
            ['set_number', 'asc'],
        ])->values()->all();
    }
}
