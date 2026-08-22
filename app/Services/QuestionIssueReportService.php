<?php

namespace App\Services;

use App\Mail\QuestionCorrectReattempt;
use App\Models\ContentQuestionCorrection;
use App\Models\FormulaDrillItem;
use App\Models\GuidedAttemptQuestion;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\QuestionIssueReport;
use App\Models\SetAttempt;
use App\Models\SetAttemptAnswer;
use App\Models\Student;
use App\Models\User;
use App\Support\AssignmentMailer;
use App\Support\RegistrationMailer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class QuestionIssueReportService
{
    public function __construct(
        private PracticeCorrectionQueueService $correctionQueue,
        private ContentUploadTaskService $contentUploadTasks,
    ) {}

    /**
     * Student flags the current guided sum as misprint/incomplete — no marks lost.
     *
     * @return array<string, mixed>
     */
    public function reportFromGuided(SetAttempt $attempt, GuidedPracticeService $guidedPractice): array
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || ! $attempt->isGuided()) {
            throw new \InvalidArgumentException('This guided practice session is not active.');
        }

        $attempt->loadMissing([
            'guidedQuestions.question',
            'assignment.enrollment.student',
        ]);

        $current = $attempt->guidedQuestions->firstWhere('sort_order', $attempt->current_question_index);

        if (! $current || ! in_array($current->phase, [
            GuidedAttemptQuestion::PHASE_ANSWERING,
            GuidedAttemptQuestion::PHASE_RETRY,
            GuidedAttemptQuestion::PHASE_EXPLAINED,
        ], true)) {
            throw new \InvalidArgumentException('You cannot report this question right now.');
        }

        $student = $attempt->assignment?->enrollment?->student;

        if (! $student) {
            throw new \InvalidArgumentException('Student not found for this attempt.');
        }

        return DB::transaction(function () use ($attempt, $current, $student, $guidedPractice) {
            $this->createOrRefreshReport([
                'student_id' => $student->id,
                'student_enrollment_id' => $attempt->assignment->student_enrollment_id,
                'question_id' => $current->question_id,
                'set_assignment_id' => $attempt->assignment_id,
                'set_attempt_id' => $attempt->id,
                'guided_attempt_question_id' => $current->id,
                'context' => QuestionIssueReport::CONTEXT_GUIDED,
            ]);

            $current->update([
                'phase' => GuidedAttemptQuestion::PHASE_REPORTED_ISSUE,
                'reported_issue' => true,
                'final_is_correct' => false,
            ]);

            $guidedPractice->advanceAfterIssueReport($attempt);

            $payload = $guidedPractice->buildPayload($attempt->fresh([
                'assignment.practiceSet',
                'guidedQuestions.question.options',
                'guidedQuestions.question.blankAnswer',
            ]));
            $payload['issue_reported'] = true;
            $payload['guided_feedback'] = [
                'type' => 'issue_reported',
                'message' => 'Thanks — this sum was sent to your teacher. No marks were lost. You will try it again after it is fixed.',
            ];

            return $payload;
        });
    }

    /**
     * Flag a chapter-test question during the attempt (excluded from score on submit).
     */
    public function reportFromBatch(SetAttempt $attempt, Question $question): QuestionIssueReport
    {
        if ($attempt->status !== SetAttempt::STATUS_IN_PROGRESS || $attempt->isGuided()) {
            throw new \InvalidArgumentException('This test attempt is not active.');
        }

        $attempt->loadMissing(['assignment.enrollment.student', 'assignment.practiceSet.questions']);

        $belongs = $attempt->assignment?->practiceSet?->questions
            ?->contains(fn (Question $q) => (int) $q->id === (int) $question->id);

        if (! $belongs) {
            throw new \InvalidArgumentException('That question is not part of this test.');
        }

        $student = $attempt->assignment?->enrollment?->student;

        if (! $student) {
            throw new \InvalidArgumentException('Student not found for this attempt.');
        }

        return $this->createOrRefreshReport([
            'student_id' => $student->id,
            'student_enrollment_id' => $attempt->assignment->student_enrollment_id,
            'question_id' => $question->id,
            'set_assignment_id' => $attempt->assignment_id,
            'set_attempt_id' => $attempt->id,
            'context' => QuestionIssueReport::CONTEXT_BATCH,
        ]);
    }

    /**
     * @return list<int>
     */
    public function reportedQuestionIdsForAttempt(SetAttempt $attempt): array
    {
        return QuestionIssueReport::query()
            ->where('set_attempt_id', $attempt->id)
            ->where('score_forfeited', false)
            ->whereIn('status', [
                QuestionIssueReport::STATUS_PENDING_ADMIN,
                QuestionIssueReport::STATUS_AWAITING_REATTEMPT,
                QuestionIssueReport::STATUS_CLEARED,
            ])
            ->pluck('question_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Skip a formula-drill item as content issue — not counted as wrong.
     *
     * @return array<string, mixed>
     */
    public function reportFromFormulaDrill(
        Student $student,
        FormulaDrillItem $item,
        FormulaDrillSessionService $sessionService,
    ): array {
        $session = $item->session;

        if (! $session || (int) $session->student_id !== (int) $student->id) {
            throw new \InvalidArgumentException('This drill item does not belong to you.');
        }

        if ($session->isComplete()) {
            throw new \InvalidArgumentException('Today\'s formula drill is already complete.');
        }

        if ($item->isDone()) {
            throw new \InvalidArgumentException('This drill item is already answered.');
        }

        $enrollment = $student->currentEnrollment();

        return DB::transaction(function () use ($student, $item, $session, $enrollment, $sessionService) {
            $this->createOrRefreshReport([
                'student_id' => $student->id,
                'student_enrollment_id' => $enrollment?->id,
                'question_id' => $item->question_id,
                'formula_drill_item_id' => $item->id,
                'context' => QuestionIssueReport::CONTEXT_FORMULA_DRILL,
            ]);

            return $sessionService->skipItemAsContentIssue($session, $item);
        });
    }

    /**
     * Admin corrected the sum — queue it for the student to attempt again.
     */
    public function markFixedAndReturnToStudent(QuestionIssueReport $report, User $admin, ?string $note = null): void
    {
        if (! $report->isPendingAdmin()) {
            throw new \InvalidArgumentException('This report is no longer waiting for a fix.');
        }

        $report->loadMissing(['question.topic', 'student', 'assignment']);

        DB::transaction(function () use ($report, $admin, $note) {
            $this->enqueueReattempt($report);

            $report->update([
                'status' => QuestionIssueReport::STATUS_AWAITING_REATTEMPT,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'admin_note' => $note,
            ]);
        });
    }

    public function dismiss(QuestionIssueReport $report, User $admin, ?string $note = null): void
    {
        if (! $report->isPendingAdmin()) {
            throw new \InvalidArgumentException('This report is no longer waiting for a fix.');
        }

        $report->update([
            'status' => QuestionIssueReport::STATUS_DISMISSED,
            'resolved_by' => $admin->id,
            'resolved_at' => now(),
            'admin_note' => $note,
        ]);
    }

    /**
     * Question and key are fine — student must re-attempt; original marks stay 0.
     */
    public function confirmQuestionCorrectRequireReattempt(
        QuestionIssueReport $report,
        User $admin,
        ?string $note = null,
    ): array {
        if (! $report->isPendingAdmin()) {
            throw new \InvalidArgumentException('This report is no longer waiting for a fix.');
        }

        $report->loadMissing([
            'question.topic',
            'question.worksheets:id,set_code,set_number',
            'student.user',
            'assignment.practiceSet:id,set_code,set_number',
            'attempt.guidedQuestions',
            'attempt.answers',
            'attempt.assignment.practiceSet.questions',
        ]);

        if (! $report->question) {
            throw new \InvalidArgumentException('This question is no longer available.');
        }

        DB::transaction(function () use ($report, $admin, $note) {
            $this->forfeitOriginalScore($report);
            $this->enqueueReattempt($report, PracticeCorrectionItem::REASON_QUESTION_CORRECT);

            $extra = trim((string) $note);
            $adminNote = 'Question is correct — please re-attempt (0 marks)'
                .($extra !== '' ? ': '.$extra : '');

            $report->update([
                'status' => QuestionIssueReport::STATUS_AWAITING_REATTEMPT,
                'reason' => QuestionIssueReport::REASON_QUESTION_CORRECT,
                'score_forfeited' => true,
                'resolved_by' => $admin->id,
                'resolved_at' => now(),
                'admin_note' => $adminNote,
            ]);
        });

        return $this->notifyStudentQuestionCorrect($report->fresh([
            'student.user',
            'question',
            'assignment.practiceSet:id,set_code',
        ]));
    }

    /**
     * Send only this sum to the content uploader. Report stays open until admin marks fixed.
     */
    public function returnToUploader(
        QuestionIssueReport $report,
        User $admin,
        string $issue,
        ?string $remark = null,
    ): void {
        if (! $report->isPendingAdmin()) {
            throw new \InvalidArgumentException('This report is no longer waiting for a fix.');
        }

        $report->loadMissing('question');

        if (! $report->question) {
            throw new \InvalidArgumentException('This question is no longer available.');
        }

        $this->contentUploadTasks->returnHelpRequestQuestion(
            $report->question,
            $admin,
            $issue,
            $remark,
            'Student reported misprint/incomplete. Fix only this sum.',
            ContentQuestionCorrection::SOURCE_ADMIN_RETURN,
        );

        $label = match ($issue) {
            'wrong_answer' => 'wrong answer',
            'incomplete' => 'incomplete sum',
            default => 'content issue',
        };

        $extra = trim((string) $remark);
        $note = 'Sent to uploader ('.$label.')'
            .($extra !== '' ? ': '.$extra : '');

        $report->update([
            'admin_note' => $note,
        ]);
    }

    public function clearAwaitingForStudentQuestion(int $studentId, int $questionId): void
    {
        QuestionIssueReport::query()
            ->where('student_id', $studentId)
            ->where('question_id', $questionId)
            ->where('status', QuestionIssueReport::STATUS_AWAITING_REATTEMPT)
            ->update([
                'status' => QuestionIssueReport::STATUS_CLEARED,
                'resolved_at' => now(),
            ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForAdmin(?array $studentIds = null, int $limit = 40): array
    {
        $query = QuestionIssueReport::query()
            ->with([
                'student:id,name',
                'enrollment.gradeLevel:id,name',
                'question:id,question_text,type,diagram_path',
                'question.options',
                'question.blankAnswer',
                'question.worksheets:id,set_code,set_number',
                'assignment.practiceSet:id,set_code,set_number',
            ])
            ->where('status', QuestionIssueReport::STATUS_PENDING_ADMIN)
            ->orderByDesc('reported_at');

        if ($studentIds !== null) {
            if ($studentIds === []) {
                return [];
            }
            $query->whereIn('student_id', $studentIds);
        }

        return $query->limit($limit)
            ->get()
            ->filter(fn (QuestionIssueReport $report) => $report->question !== null)
            ->values()
            ->pipe(function (Collection $reports) {
                $uploaderByQuestion = $this->contentUploadTasks->tasksKeyedByQuestionId(
                    $reports->pluck('question_id')->all(),
                );

                return $reports
                    ->map(fn (QuestionIssueReport $report) => $this->formatAdminItem(
                        $report,
                        $uploaderByQuestion->get((int) $report->question_id),
                    ))
                    ->values()
                    ->all();
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pendingForStudent(int $studentId): array
    {
        $reports = QuestionIssueReport::query()
            ->with([
                'question:id,question_text,type,diagram_path',
                'question.options',
                'question.blankAnswer',
                'question.worksheets:id,set_code,set_number',
                'assignment.practiceSet:id,set_code,set_number',
            ])
            ->where('student_id', $studentId)
            ->where('status', QuestionIssueReport::STATUS_PENDING_ADMIN)
            ->orderByDesc('reported_at')
            ->get()
            ->filter(fn (QuestionIssueReport $report) => $report->question !== null)
            ->values();

        $uploaderByQuestion = $this->contentUploadTasks->tasksKeyedByQuestionId(
            $reports->pluck('question_id')->all(),
        );

        return $reports
            ->map(fn (QuestionIssueReport $report) => $this->formatAdminItem(
                $report,
                $uploaderByQuestion->get((int) $report->question_id),
            ))
            ->values()
            ->all();
    }

    public function pendingCountForStudentIds(array $studentIds): Collection
    {
        if ($studentIds === []) {
            return collect();
        }

        return QuestionIssueReport::query()
            ->selectRaw('student_id, count(*) as c')
            ->where('status', QuestionIssueReport::STATUS_PENDING_ADMIN)
            ->whereIn('student_id', $studentIds)
            ->groupBy('student_id')
            ->pluck('c', 'student_id');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOrRefreshReport(array $data): QuestionIssueReport
    {
        $existing = QuestionIssueReport::query()
            ->where('student_id', $data['student_id'])
            ->where('question_id', $data['question_id'])
            ->where('status', QuestionIssueReport::STATUS_PENDING_ADMIN)
            ->first();

        $payload = [
            ...$data,
            'reason' => QuestionIssueReport::REASON_MISPRINT_INCOMPLETE,
            'status' => QuestionIssueReport::STATUS_PENDING_ADMIN,
            'reported_at' => now(),
            'resolved_by' => null,
            'resolved_at' => null,
            'admin_note' => null,
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return QuestionIssueReport::query()->create($payload);
    }

    private function enqueueReattempt(QuestionIssueReport $report, ?string $failureReason = null): void
    {
        $question = $report->question;
        $worksheetId = $report->assignment?->worksheet_id
            ?? $question?->worksheets->first()?->id;

        $chapterId = $question?->topic?->syllabus_chapter_id
            ?? $question?->loadMissing('topic')->topic?->syllabus_chapter_id;

        $source = match ($report->context) {
            QuestionIssueReport::CONTEXT_BATCH => PracticeCorrectionItem::SOURCE_BATCH_TEST,
            QuestionIssueReport::CONTEXT_FORMULA_DRILL => PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE,
            default => PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE,
        };

        $this->correctionQueue->recordContentFixedPending([
            'student_id' => $report->student_id,
            'question_id' => $report->question_id,
            'syllabus_chapter_id' => $chapterId,
            'worksheet_id' => $worksheetId,
            'set_assignment_id' => $report->set_assignment_id,
            'set_attempt_id' => $report->set_attempt_id,
            'guided_attempt_question_id' => $report->guided_attempt_question_id,
            'source_type' => $source,
            'failure_reason' => $failureReason ?? PracticeCorrectionItem::REASON_CONTENT_FIXED,
            'first_failure_at' => $report->reported_at ?? now(),
        ]);
    }

    /**
     * Convert a “no marks lost” report into a scored wrong (0) on the original attempt.
     */
    private function forfeitOriginalScore(QuestionIssueReport $report): void
    {
        if ($report->context === QuestionIssueReport::CONTEXT_GUIDED) {
            $this->forfeitGuidedScore($report);

            return;
        }

        if ($report->context === QuestionIssueReport::CONTEXT_BATCH) {
            $this->forfeitBatchScore($report);
        }
    }

    private function forfeitGuidedScore(QuestionIssueReport $report): void
    {
        $guided = $report->guided_attempt_question_id
            ? GuidedAttemptQuestion::query()->find($report->guided_attempt_question_id)
            : null;

        if (! $guided) {
            return;
        }

        $guided->update([
            'reported_issue' => false,
            'final_is_correct' => false,
            'first_try_correct' => false,
            'phase' => GuidedAttemptQuestion::PHASE_DONE,
        ]);

        $attempt = $report->attempt ?? SetAttempt::query()->find($report->set_attempt_id);

        if (! $attempt) {
            return;
        }

        SetAttemptAnswer::updateOrCreate(
            [
                'set_attempt_id' => $attempt->id,
                'question_id' => $report->question_id,
            ],
            [
                'question_option_id' => $guided->final_option_id,
                'answer_text' => $guided->final_answer_text,
                'is_correct' => false,
            ],
        );

        if ($attempt->status === SetAttempt::STATUS_SUBMITTED) {
            $this->recalculateGuidedAttemptTotals($attempt->fresh('guidedQuestions'));
        }
    }

    private function recalculateGuidedAttemptTotals(SetAttempt $attempt): void
    {
        $rows = $attempt->guidedQuestions;
        $scorable = $rows->filter(fn (GuidedAttemptQuestion $row) => ! $row->reported_issue
            && $row->phase !== GuidedAttemptQuestion::PHASE_REPORTED_ISSUE);
        $firstTryCorrect = $scorable->where('first_try_correct', true)->count();
        $correctedAfterHelp = $scorable->where('corrected_after_help', true)->count();
        $givenUp = $scorable->where('gave_up', true)->count();

        $attempt->update([
            'score' => $firstTryCorrect,
            'max_score' => $scorable->count(),
            'first_try_correct_count' => $firstTryCorrect,
            'corrected_after_help_count' => $correctedAfterHelp,
            'given_up_count' => $givenUp,
        ]);
    }

    private function forfeitBatchScore(QuestionIssueReport $report): void
    {
        $attempt = $report->attempt ?? SetAttempt::query()->find($report->set_attempt_id);

        if (! $attempt) {
            return;
        }

        SetAttemptAnswer::updateOrCreate(
            [
                'set_attempt_id' => $attempt->id,
                'question_id' => $report->question_id,
            ],
            [
                'question_option_id' => null,
                'answer_text' => null,
                'is_correct' => false,
            ],
        );

        if ($attempt->status !== SetAttempt::STATUS_SUBMITTED) {
            return;
        }

        $attempt->loadMissing(['assignment.practiceSet.questions', 'answers']);
        $questions = $attempt->assignment?->practiceSet?->questions ?? collect();
        $reportedSkipIds = $this->reportedQuestionIdsForAttempt($attempt);
        // Current report is still pending_admin until after this method; treat it as forfeited for recalc.
        $reportedSkipIds = array_values(array_filter(
            $reportedSkipIds,
            fn (int $id) => $id !== (int) $report->question_id,
        ));

        $score = 0;
        $maxScore = 0;

        foreach ($questions as $question) {
            if (in_array((int) $question->id, $reportedSkipIds, true)) {
                continue;
            }

            $maxScore++;
            $answer = $attempt->answers->firstWhere('question_id', $question->id);
            if ($answer?->is_correct) {
                $score++;
            }
        }

        $attempt->update([
            'score' => $score,
            'max_score' => $maxScore,
        ]);
    }

    /**
     * @return array{sent: bool, email: ?string, error: ?string}
     */
    private function notifyStudentQuestionCorrect(QuestionIssueReport $report): array
    {
        $student = $report->student;

        if (! $student) {
            return ['sent' => false, 'email' => null, 'error' => 'no_student'];
        }

        $email = AssignmentMailer::resolveStudentEmail($student);

        if (! $email) {
            return ['sent' => false, 'email' => null, 'error' => 'no_email'];
        }

        $preview = Str::limit(trim(strip_tags((string) ($report->question?->question_text ?? ''))), 220);
        $setCode = $report->assignment?->practiceSet?->set_code
            ?? $report->question?->worksheets?->first()?->set_code;

        try {
            $pending = Mail::to($email);
            $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

            if ($adminEmail && strcasecmp($adminEmail, $email) !== 0) {
                $pending->cc($adminEmail);
            }

            $pending->send(new QuestionCorrectReattempt($student, $report, $preview, $setCode));

            return ['sent' => true, 'email' => $email, 'error' => null];
        } catch (\Throwable $e) {
            Log::error('Failed to send question-correct reattempt email.', [
                'report_id' => $report->id,
                'student_id' => $student->id,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            return ['sent' => false, 'email' => $email, 'error' => 'send_failed'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatAdminItem(QuestionIssueReport $report, ?\App\Models\ContentUploadTask $uploadTask = null): array
    {
        $worksheet = $report->assignment?->practiceSet
            ?? $report->question?->worksheets->first();

        $setCode = $worksheet?->set_code;
        $worksheetId = $worksheet?->id;
        $questionId = $report->question_id;
        $question = $report->question;

        $setUrl = null;
        if ($worksheetId) {
            $setUrl = route('admin.questions.sets.show', $worksheetId);
        } elseif (filled($setCode)) {
            $setUrl = route('admin.questions.set-code', ['code' => $setCode]);
        }

        $checkUrl = null;
        if ($question && filled($setCode) && $setUrl) {
            $checkUrl = $setUrl.'#question-'.$question->id;
        } elseif ($question) {
            $checkUrl = route('admin.questions.edit', $question);
        }

        $editUrl = null;
        if ($question) {
            $editUrl = $question->isFillInBlank() && filled($setCode)
                ? route('admin.questions.set-code', ['code' => $setCode]).'#question-'.$question->id
                : route('admin.questions.edit', $question);
        }

        $options = [];
        $correctAnswer = null;
        if ($question?->isMcq()) {
            $question->loadMissing('options');
            $options = $question->options->values()->map(function ($option, $index) {
                return [
                    'id' => $option->id,
                    'letter' => chr(65 + $index),
                    'option_text' => $option->option_text,
                    'is_correct' => (bool) $option->is_correct,
                ];
            })->all();
            $correctOption = collect($options)->firstWhere('is_correct');
            $correctAnswer = $correctOption['letter'] ?? null;
        } elseif ($question?->isFillInBlank()) {
            $question->loadMissing('blankAnswer');
            $correctAnswer = $question->blankAnswer?->correct_answer;
        }

        return [
            'id' => $report->id,
            'student_id' => $report->student_id,
            'student_name' => $report->student?->name,
            'class_name' => $report->enrollment?->gradeLevel?->name,
            'question_id' => $questionId,
            'question_type' => $question?->type,
            'question_text' => mb_convert_encoding((string) ($question?->question_text ?? ''), 'UTF-8', 'UTF-8'),
            'diagram_url' => $question?->diagram_url,
            'options' => $options,
            'correct_answer' => $correctAnswer,
            'admin_note' => $report->admin_note,
            'context' => $report->context,
            'context_label' => match ($report->context) {
                QuestionIssueReport::CONTEXT_BATCH => 'Chapter test',
                QuestionIssueReport::CONTEXT_FORMULA_DRILL => 'Formula drill',
                default => 'Guided practice',
            },
            'set_code' => $setCode,
            'set_number' => $worksheet?->set_number,
            'worksheet_id' => $worksheetId,
            'set_url' => $setUrl,
            'check_url' => $checkUrl,
            'edit_url' => $editUrl,
            'reported_at' => $report->reported_at?->toDateTimeString(),
            ...$this->contentUploadTasks->uploaderReturnPayload($uploadTask),
        ];
    }
}
