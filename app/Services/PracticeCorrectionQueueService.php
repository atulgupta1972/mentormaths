<?php

namespace App\Services;

use App\Models\ExamPlan;
use App\Models\GuidedAttemptQuestion;
use App\Models\PracticeCorrectionItem;
use App\Models\Question;
use App\Models\SetAttempt;
use App\Models\Student;
use App\Models\WrittenSubmission;
use App\Models\WrittenSubmissionItem;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PracticeCorrectionQueueService
{
    /**
     * @return array{guided: int, batch: int, written: int, pending: int}
     */
    public function backfill(?int $studentId = null): array
    {
        $stats = [
            'guided' => 0,
            'batch' => 0,
            'written' => 0,
            'pending' => 0,
        ];

        $guidedQuery = GuidedAttemptQuestion::query()
            ->whereIn('phase', [
                GuidedAttemptQuestion::PHASE_DONE,
                GuidedAttemptQuestion::PHASE_GIVEN_UP,
            ])
            ->with([
                'attempt.assignment.enrollment.student',
                'attempt.assignment.practiceSet',
            ]);

        if ($studentId !== null) {
            $guidedQuery->whereHas(
                'attempt.assignment.enrollment',
                fn ($query) => $query->where('student_id', $studentId),
            );
        }

        foreach ($guidedQuery->cursor() as $guided) {
            $attempt = $guided->attempt;

            if (! $attempt) {
                continue;
            }

            $this->syncFromGuidedQuestion($guided, $attempt);
            $stats['guided']++;
        }

        $batchQuery = SetAttempt::query()
            ->where('status', SetAttempt::STATUS_SUBMITTED)
            ->where('mode', SetAttempt::MODE_BATCH)
            ->with([
                'answers',
                'assignment.enrollment.student',
                'assignment.practiceSet',
            ]);

        if ($studentId !== null) {
            $batchQuery->whereHas(
                'assignment.enrollment',
                fn ($query) => $query->where('student_id', $studentId),
            );
        }

        foreach ($batchQuery->cursor() as $attempt) {
            $this->syncFromBatchAttempt($attempt);
            $stats['batch']++;
        }

        $writtenQuery = WrittenSubmission::query()
            ->with([
                'items',
                'assignment.enrollment.student',
                'assignment.practiceSet',
            ])
            ->whereHas('items');

        if ($studentId !== null) {
            $writtenQuery->whereHas(
                'assignment.enrollment',
                fn ($query) => $query->where('student_id', $studentId),
            );
        }

        foreach ($writtenQuery->cursor() as $submission) {
            $this->syncFromWrittenSubmission($submission);
            $stats['written']++;
        }

        $stats['pending'] = PracticeCorrectionItem::query()
            ->when($studentId !== null, fn ($query) => $query->where('student_id', $studentId))
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->count();

        return $stats;
    }

    /**
     * Sync queue state when a guided question reaches done / gave up.
     */
    public function syncFromGuidedQuestion(GuidedAttemptQuestion $guided, SetAttempt $attempt): void
    {
        if (! $guided->isFinished()) {
            return;
        }

        $context = $this->attemptContext($attempt);

        if ($context === null) {
            return;
        }

        if ($guided->first_try_correct) {
            $this->markCorrected(
                $context['student_id'],
                $guided->question_id,
                PracticeCorrectionItem::CORRECTED_IN_GUIDED_PRACTICE,
            );

            return;
        }

        $this->recordPending([
            'student_id' => $context['student_id'],
            'question_id' => $guided->question_id,
            'syllabus_chapter_id' => $context['syllabus_chapter_id'],
            'worksheet_id' => $context['worksheet_id'],
            'set_assignment_id' => $context['set_assignment_id'],
            'set_attempt_id' => $attempt->id,
            'guided_attempt_question_id' => $guided->id,
            'source_type' => PracticeCorrectionItem::SOURCE_GUIDED_PRACTICE,
            'failure_reason' => $this->failureReasonFromGuided($guided),
            'first_failure_at' => $guided->updated_at ?? now(),
        ]);
    }

    /**
     * Queue wrong answers from a submitted batch (chapter test) attempt.
     */
    public function syncFromBatchAttempt(SetAttempt $attempt): void
    {
        if (! $attempt->isGuided()) {
            $attempt->loadMissing('answers');
        }

        $context = $this->attemptContext($attempt);

        if ($context === null) {
            return;
        }

        if ($attempt->isGuided()) {
            $attempt->loadMissing('guidedQuestions');

            foreach ($attempt->guidedQuestions as $guided) {
                $this->syncFromGuidedQuestion($guided, $attempt);
            }

            return;
        }

        foreach ($attempt->answers as $answer) {
            if ($answer->is_correct) {
                $this->markCorrected(
                    $context['student_id'],
                    $answer->question_id,
                    PracticeCorrectionItem::CORRECTED_IN_BATCH_TEST,
                );

                continue;
            }

            $this->recordPending([
                'student_id' => $context['student_id'],
                'question_id' => $answer->question_id,
                'syllabus_chapter_id' => $context['syllabus_chapter_id'],
                'worksheet_id' => $context['worksheet_id'],
                'set_assignment_id' => $context['set_assignment_id'],
                'set_attempt_id' => $attempt->id,
                'source_type' => PracticeCorrectionItem::SOURCE_BATCH_TEST,
                'failure_reason' => 'batch_wrong',
                'first_failure_at' => $answer->updated_at ?? $attempt->completed_at ?? now(),
            ]);
        }
    }

    /**
     * Queue wrong written items after AI or manual grading.
     */
    public function syncFromWrittenSubmission(WrittenSubmission $submission): void
    {
        $submission->loadMissing([
            'items',
            'assignment.enrollment.student',
            'assignment.practiceSet',
        ]);

        $assignment = $submission->assignment;

        if (! $assignment?->enrollment?->student) {
            return;
        }

        $studentId = (int) $assignment->enrollment->student_id;
        $worksheetId = (int) $assignment->worksheet_id;
        $chapterId = $this->chapterIdForWorksheet($assignment->practiceSet);

        foreach ($submission->items as $item) {
            $this->syncWrittenItem($item, $studentId, $worksheetId, $chapterId, $assignment->id, $submission->id);
        }
    }

    public function markCorrected(int $studentId, int $questionId, string $correctedIn): void
    {
        PracticeCorrectionItem::query()
            ->where('student_id', $studentId)
            ->where('question_id', $questionId)
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->update([
                'status' => PracticeCorrectionItem::STATUS_CORRECTED,
                'corrected_at' => now(),
                'corrected_in' => $correctedIn,
            ]);
    }

    /**
     * @return Collection<int, PracticeCorrectionItem>
     */
    public function pendingForStudent(int $studentId): Collection
    {
        return PracticeCorrectionItem::query()
            ->where('student_id', $studentId)
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->orderBy('first_failure_at')
            ->get();
    }

    public function pendingCountForStudent(int $studentId): int
    {
        return PracticeCorrectionItem::query()
            ->where('student_id', $studentId)
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->count();
    }

    /**
     * @return Collection<int, PracticeCorrectionItem>
     */
    public function pendingForWorksheet(int $studentId, int $worksheetId): Collection
    {
        return PracticeCorrectionItem::query()
            ->where('student_id', $studentId)
            ->where('worksheet_id', $worksheetId)
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->orderBy('first_failure_at')
            ->get();
    }

    /**
     * Pick up to $limit pending items, prioritising exam-scheduled chapters.
     *
     * @return Collection<int, PracticeCorrectionItem>
     */
    public function selectForDailyDrill(Student $student, int $limit = 5): Collection
    {
        $pending = $this->pendingForStudent($student->id);

        if ($pending->isEmpty()) {
            return collect();
        }

        $examChapterIds = $this->scheduledExamChapterIds($student);

        return $pending
            ->sortBy(function (PracticeCorrectionItem $item) use ($examChapterIds) {
                $examBoost = $item->syllabus_chapter_id
                    && in_array((int) $item->syllabus_chapter_id, $examChapterIds, true)
                    ? 0
                    : 1;

                return [$examBoost, $item->first_failure_at?->timestamp ?? 0];
            })
            ->take($limit)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function recordPending(array $data): PracticeCorrectionItem
    {
        $existing = PracticeCorrectionItem::query()
            ->where('student_id', $data['student_id'])
            ->where('question_id', $data['question_id'])
            ->where('status', PracticeCorrectionItem::STATUS_PENDING)
            ->first();

        $firstFailureAt = $data['first_failure_at'] instanceof Carbon
            ? $data['first_failure_at']
            : Carbon::parse($data['first_failure_at']);

        if ($existing) {
            $existing->update([
                'syllabus_chapter_id' => $data['syllabus_chapter_id'] ?? $existing->syllabus_chapter_id,
                'worksheet_id' => $data['worksheet_id'] ?? $existing->worksheet_id,
                'set_assignment_id' => $data['set_assignment_id'] ?? $existing->set_assignment_id,
                'set_attempt_id' => $data['set_attempt_id'] ?? $existing->set_attempt_id,
                'guided_attempt_question_id' => $data['guided_attempt_question_id'] ?? $existing->guided_attempt_question_id,
                'written_submission_id' => $data['written_submission_id'] ?? $existing->written_submission_id,
                'source_type' => $data['source_type'],
                'failure_reason' => $data['failure_reason'],
                'first_failure_at' => min(
                    $existing->first_failure_at ?? $firstFailureAt,
                    $firstFailureAt,
                ),
            ]);

            return $existing->fresh();
        }

        return PracticeCorrectionItem::query()->create([
            ...$data,
            'status' => PracticeCorrectionItem::STATUS_PENDING,
            'first_failure_at' => $firstFailureAt,
        ]);
    }

    private function syncWrittenItem(
        WrittenSubmissionItem $item,
        int $studentId,
        int $worksheetId,
        ?int $chapterId,
        int $assignmentId,
        int $submissionId,
    ): void {
        if ($item->is_correct) {
            $this->markCorrected($studentId, $item->question_id, PracticeCorrectionItem::SOURCE_WRITTEN);

            return;
        }

        $this->recordPending([
            'student_id' => $studentId,
            'question_id' => $item->question_id,
            'syllabus_chapter_id' => $chapterId,
            'worksheet_id' => $worksheetId,
            'set_assignment_id' => $assignmentId,
            'written_submission_id' => $submissionId,
            'source_type' => PracticeCorrectionItem::SOURCE_WRITTEN,
            'failure_reason' => 'written_wrong',
            'first_failure_at' => $item->updated_at ?? now(),
        ]);
    }

    private function failureReasonFromGuided(GuidedAttemptQuestion $guided): string
    {
        if ($guided->gave_up) {
            return 'gave_up';
        }

        if ($guided->corrected_after_help) {
            return 'after_hint';
        }

        if ((int) ($guided->wrong_before_explanation ?? 0) >= 2) {
            return 'second_wrong';
        }

        if ($guided->used_early_hint) {
            return 'after_hint';
        }

        return 'first_wrong';
    }

    /**
     * @return array{student_id: int, worksheet_id: int, set_assignment_id: int, syllabus_chapter_id: ?int}|null
     */
    private function attemptContext(SetAttempt $attempt): ?array
    {
        $attempt->loadMissing([
            'assignment.enrollment.student',
            'assignment.practiceSet.topic',
            'assignment.practiceSet.chapter',
        ]);

        $studentId = $attempt->assignment?->enrollment?->student_id;

        if (! $studentId) {
            return null;
        }

        $worksheet = $attempt->assignment->practiceSet;

        return [
            'student_id' => (int) $studentId,
            'worksheet_id' => (int) $attempt->assignment->worksheet_id,
            'set_assignment_id' => (int) $attempt->assignment->id,
            'syllabus_chapter_id' => $this->chapterIdForWorksheet($worksheet),
        ];
    }

    private function chapterIdForWorksheet(?\App\Models\Worksheet $worksheet): ?int
    {
        if (! $worksheet) {
            return null;
        }

        if ($worksheet->syllabus_chapter_id) {
            return (int) $worksheet->syllabus_chapter_id;
        }

        $worksheet->loadMissing('topic');

        return $worksheet->topic?->syllabus_chapter_id
            ? (int) $worksheet->topic->syllabus_chapter_id
            : null;
    }

    /**
     * @return list<int>
     */
    private function scheduledExamChapterIds(Student $student): array
    {
        $enrollment = $student->currentEnrollment();

        if (! $enrollment) {
            return [];
        }

        return ExamPlan::query()
            ->where('student_enrollment_id', $enrollment->id)
            ->where('status', ExamPlan::STATUS_PLANNED)
            ->whereDate('exam_date', '>=', now()->toDateString())
            ->with('chapters:id')
            ->get()
            ->flatMap(fn (ExamPlan $plan) => $plan->chapters->pluck('id'))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
