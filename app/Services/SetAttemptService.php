<?php

namespace App\Services;

use App\Models\SetAssignment;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\StudentEnrollment;
use App\Support\AssignmentMailer;
use App\Support\AssignmentProgress;
use App\Support\AttemptIntegrity;
use App\Support\AttemptResultSummary;
use App\Support\AttemptTiming;
use App\Support\AnswerValidationService;
use Illuminate\Support\Facades\DB;

class SetAttemptService
{
    public function __construct(
        private GuidedPracticeService $guidedPractice,
        private AnswerValidationService $answerValidation,
        private PracticeCorrectionQueueService $correctionQueue,
        private ClassCoverageService $classCoverage,
        private AssignmentPoolScore $poolScore,
    ) {}

    public function start(SetAssignment $assignment): SetAttempt
    {
        $inProgress = $assignment->attempts()
            ->where('status', SetAttempt::STATUS_IN_PROGRESS)
            ->first();

        if ($inProgress) {
            $this->ensureGuidedForTopicPractice($inProgress);
            $this->poolScore->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

            return $inProgress->fresh();
        }

        if ($assignment->status === SetAssignment::STATUS_COMPLETED) {
            throw new \InvalidArgumentException('Ask your teacher to allow another attempt.');
        }

        $this->assertChapterMarkedForStudy($assignment);

        $nextNumber = ($assignment->attempts()->max('attempt_number') ?? 0) + 1;

        $assignment->loadMissing('practiceSet');

        return DB::transaction(function () use ($assignment, $nextNumber) {
            $this->poolScore->ensureOriginals($assignment->fresh(['enrollment', 'practiceSet.questions']));

            $attempt = SetAttempt::create([
                'set_assignment_id' => $assignment->id,
                'attempt_number' => $nextNumber,
                'mode' => $assignment->practiceSet->isChapterTest()
                    ? SetAttempt::MODE_BATCH
                    : SetAttempt::MODE_GUIDED,
                'started_at' => now(),
                'active_seconds' => 0,
                'active_session_started_at' => now(),
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

    public function recordTabLeave(SetAttempt $attempt): SetAttempt
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS) {
            return $attempt;
        }

        $attempt->increment('tab_leave_count');

        return $attempt->fresh();
    }

    public function unlockIntegrityLock(SetAttempt $attempt): SetAttempt
    {
        $attempt->update(['tab_leave_count' => 0]);

        return $attempt->fresh();
    }

    public function assertNotIntegrityLocked(SetAttempt $attempt): void
    {
        $attempt->loadMissing('assignment.enrollment.gradeLevel', 'assignment.practiceSet');

        if (AttemptIntegrity::isLocked($attempt)) {
            throw new \InvalidArgumentException(
                'This attempt is locked after '.AttemptIntegrity::TAB_LEAVE_LOCK_LIMIT
                .' tab switches. Ask your teacher to unlock it from the Dashboard (Locked students).'
            );
        }
    }

    /**
     * Persist one MCQ choice during a batch (chapter test) attempt without grading.
     * Survives refresh / WiFi drops until final submit.
     *
     * @return array{answered: int, total: int, answers: array<int, int>}
     */
    public function saveDraftAnswer(SetAttempt $attempt, int $questionId, ?int $optionId): array
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || $attempt->isGuided()) {
            throw new \InvalidArgumentException('This test attempt is not active.');
        }

        $this->assertNotIntegrityLocked($attempt);

        $attempt->loadMissing('assignment.practiceSet.questions.options');
        $questions = $attempt->assignment->practiceSet->questions;
        $question = $questions->firstWhere('id', $questionId);

        if (! $question) {
            throw new \InvalidArgumentException('That question is not part of this test.');
        }

        $reportedIds = app(QuestionIssueReportService::class)->reportedQuestionIdsForAttempt($attempt);
        if (in_array($questionId, $reportedIds, true)) {
            throw new \InvalidArgumentException('That question was reported and is skipped.');
        }

        if ($optionId === null) {
            SetAttemptAnswer::query()
                ->where('set_attempt_id', $attempt->id)
                ->where('question_id', $questionId)
                ->delete();
        } else {
            $option = $question->options->firstWhere('id', $optionId);
            if (! $option) {
                throw new \InvalidArgumentException('Invalid option for this question.');
            }

            SetAttemptAnswer::updateOrCreate(
                [
                    'set_attempt_id' => $attempt->id,
                    'question_id' => $questionId,
                ],
                [
                    'question_option_id' => $optionId,
                    'is_correct' => false,
                ],
            );
        }

        $drafts = $this->draftAnswersMap($attempt->fresh());
        $activeTotal = $questions->count() - count($reportedIds);

        $attempt->update([
            'current_question_index' => count($drafts),
        ]);

        return [
            'answered' => count($drafts),
            'total' => max($activeTotal, 0),
            'answers' => $drafts,
        ];
    }

    /**
     * @return array<int, int> question_id => selected option_id
     */
    public function draftAnswersMap(SetAttempt $attempt): array
    {
        $attempt->loadMissing('answers');

        $map = [];
        foreach ($attempt->answers as $answer) {
            if ($answer->question_option_id) {
                $map[(int) $answer->question_id] = (int) $answer->question_option_id;
            }
        }

        return $map;
    }

    public function clearDraftAnswer(SetAttempt $attempt, int $questionId): void
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || $attempt->isGuided()) {
            return;
        }

        SetAttemptAnswer::query()
            ->where('set_attempt_id', $attempt->id)
            ->where('question_id', $questionId)
            ->delete();

        $attempt->update([
            'current_question_index' => count($this->draftAnswersMap($attempt->fresh())),
        ]);
    }

    public function submit(SetAttempt $attempt, array $answers): SetAttempt
    {
        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException('This attempt has already been submitted.');
        }

        $this->assertNotIntegrityLocked($attempt);

        $assignment = $attempt->assignment()->with('practiceSet.questions.options')->first();
        $questions = $assignment->practiceSet->questions->keyBy('id');
        $reportedIds = app(QuestionIssueReportService::class)->reportedQuestionIdsForAttempt($attempt);

        return DB::transaction(function () use ($attempt, $answers, $assignment, $questions, $reportedIds) {
            $score = 0;
            $maxScore = 0;

            foreach ($questions as $question) {
                if (in_array((int) $question->id, $reportedIds, true)) {
                    continue;
                }

                $maxScore++;
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

            $timeSeconds = AttemptTiming::finalizeActiveTime($attempt);
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

            $freshAttempt = $attempt->fresh(['answers', 'assignment.practiceSet']);

            $this->correctionQueue->syncFromBatchAttempt($freshAttempt);
            $this->poolScore->syncFromBatchAttempt($freshAttempt);
            app(RevisionAssignmentService::class)->ensureFirstRevisionIfReady($assignment->fresh());

            AssignmentMailer::sendCompleted($freshAttempt);

            return $freshAttempt;
        });
    }

    public function ensureGuidedForTopicPractice(SetAttempt $attempt): void
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || $attempt->isGuided()) {
            return;
        }

        $attempt->loadMissing('assignment.practiceSet');

        if ($attempt->assignment->practiceSet->isChapterTest()) {
            return;
        }

        DB::transaction(function () use ($attempt) {
            $this->guidedPractice->initialize($attempt);
        });
    }

    public function dashboardForEnrollment(StudentEnrollment $enrollment): array
    {
        return $this->dashboardKeyedByEnrollmentId([$enrollment->id])[$enrollment->id] ?? [];
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function dashboardKeyedByEnrollmentId(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $assignments = SetAssignment::query()
            ->with([
                'practiceSet' => fn ($q) => $q
                    ->withCount('questions')
                    ->with(['chapter:id,name,sort_order', 'topic:id,name,syllabus_chapter_id', 'topic.chapter:id,name,sort_order']),
            ])
            ->whereIn('student_enrollment_id', $enrollmentIds)
            ->where('status', '!=', SetAssignment::STATUS_CANCELLED)
            ->whereHas('practiceSet', fn ($q) => $q->where('status', 'published'))
            ->get()
            ->filter(fn (SetAssignment $assignment) => $assignment->practiceSet !== null)
            ->values();

        $assignmentIds = $assignments->pluck('id')->all();
        $latestAttempts = $this->latestAttemptsByAssignmentId($assignmentIds);
        $latestSubmissions = $this->latestWrittenSubmissionsByAssignmentId($assignmentIds);

        $grouped = [];

        foreach ($assignments as $assignment) {
            $attempts = collect();
            $latest = $latestAttempts->get($assignment->id);
            if ($latest) {
                $attempts = collect([$latest]);
            }
            $assignment->setRelation('attempts', $attempts);

            $submissions = collect();
            $submission = $latestSubmissions->get($assignment->id);
            if ($submission) {
                $submissions = collect([$submission]);
            }
            $assignment->setRelation('writtenSubmissions', $submissions);

            if ($assignment->practiceSet->isWritten()) {
                $row = AssignmentProgress::formatWrittenStudentDashboardSummary($assignment, $submission);
            } else {
                $row = AssignmentProgress::formatStudentDashboardSummary($assignment, $latest);
            }

            $grouped[(int) $assignment->student_enrollment_id][] = $row;
        }

        foreach ($grouped as $enrollmentId => $rows) {
            $grouped[$enrollmentId] = collect($rows)->sortBy([
                ['set_code', 'asc'],
                ['set_number', 'asc'],
            ])->values()->all();
        }

        return $grouped;
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return \Illuminate\Support\Collection<int, SetAttempt>
     */
    private function latestAttemptsByAssignmentId(array $assignmentIds)
    {
        if ($assignmentIds === []) {
            return collect();
        }

        return SetAttempt::query()
            ->whereIn('id', function ($query) use ($assignmentIds) {
                $query->selectRaw('MAX(id)')
                    ->from('set_attempts')
                    ->whereIn('set_assignment_id', $assignmentIds)
                    ->groupBy('set_assignment_id');
            })
            ->get()
            ->keyBy('set_assignment_id');
    }

    /**
     * @param  list<int>  $assignmentIds
     * @return \Illuminate\Support\Collection<int, \App\Models\WrittenSubmission>
     */
    private function latestWrittenSubmissionsByAssignmentId(array $assignmentIds)
    {
        if ($assignmentIds === []) {
            return collect();
        }

        return \App\Models\WrittenSubmission::query()
            ->whereIn('id', function ($query) use ($assignmentIds) {
                $query->selectRaw('MAX(id)')
                    ->from('written_submissions')
                    ->whereIn('set_assignment_id', $assignmentIds)
                    ->groupBy('set_assignment_id');
            })
            ->get()
            ->keyBy('set_assignment_id');
    }

    /**
     * @return array{correct: bool, message: string, correct_answer: ?string}
     */
    public function checkPracticeRetry(
        SetAttempt $attempt,
        int $questionId,
        ?int $optionId = null,
        ?string $answerText = null,
    ): array {
        if ($attempt->status !== SetAttempt::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException('Practice retry is only available after submission.');
        }

        $assignment = $attempt->assignment()->with([
            'practiceSet.questions.options',
            'practiceSet.questions.blankAnswer',
        ])->first();

        $question = $assignment->practiceSet->questions->firstWhere('id', $questionId);

        if (! $question) {
            throw new \InvalidArgumentException('Question not found in this set.');
        }

        if ($attempt->isGuided()) {
            $guided = $attempt->guidedQuestions()->where('question_id', $questionId)->first();

            if ($guided?->final_is_correct) {
                throw new \InvalidArgumentException('This question was already answered correctly.');
            }
        } else {
            $answer = $attempt->answers()->where('question_id', $questionId)->first();

            if ($answer?->is_correct) {
                throw new \InvalidArgumentException('This question was already answered correctly.');
            }
        }

        $isCorrect = false;

        if ($question->isFillInBlank()) {
            if (! filled($answerText)) {
                throw new \InvalidArgumentException('Enter an answer before submitting.');
            }

            $isCorrect = $this->answerValidation->isCorrect($question, $answerText);
        } else {
            if (! $optionId) {
                throw new \InvalidArgumentException('Select an option before submitting.');
            }

            $option = $question->options->firstWhere('id', $optionId);
            $isCorrect = $option?->is_correct ?? false;
        }

        return [
            'correct' => $isCorrect,
            'message' => $isCorrect
                ? 'Correct! Well done.'
                : 'Not quite — try again.',
            'correct_answer' => $isCorrect
                ? AttemptResultSummary::correctAnswerForQuestion($question)
                : null,
        ];
    }

    private function assertChapterMarkedForStudy(SetAssignment $assignment): void
    {
        $assignment->loadMissing(['enrollment', 'practiceSet']);

        $enrollment = $assignment->enrollment;
        $worksheet = $assignment->practiceSet;

        if (! $enrollment || ! $worksheet) {
            return;
        }

        if ($this->classCoverage->enrollmentCanAttemptContent($enrollment, $worksheet, $assignment)) {
            return;
        }

        $effectiveId = $this->classCoverage->resolveEffectiveSyllabusChapterId(
            $worksheet,
            $enrollment,
            $assignment,
        );

        $homeOptions = collect($this->classCoverage->homeChapterOptionsForEnrollment($enrollment))
            ->pluck('id')
            ->all();

        if ($effectiveId && in_array($effectiveId, $homeOptions, true)) {
            throw new \InvalidArgumentException(
                'Mark this chapter as Studied or Under study on your study plan before attempting the set.'
            );
        }

        throw new \InvalidArgumentException(
            'Mark at least one chapter as Studied or Under study on your study plan before attempting this set.'
        );
    }
}
