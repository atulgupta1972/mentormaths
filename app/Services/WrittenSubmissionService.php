<?php

namespace App\Services;

use App\Jobs\GradeWrittenSubmissionJob;
use App\Models\SetAssignment;
use App\Models\WrittenSubmission;
use App\Models\WrittenSubmissionItem;
use App\Support\WrittenSubmissionLimits;
use App\Support\WrittenSubmissionMailer;
use App\Support\WrittenSubmissionProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WrittenSubmissionService
{
    public function __construct(
        private WrittenUploadOptimizer $uploadOptimizer,
        private PracticeCorrectionQueueService $correctionQueue,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @param  array{schedule_ai?: bool, append?: bool}  $options
     */
    public function store(SetAssignment $assignment, array $files, array $options = []): WrittenSubmission
    {
        $scheduleAi = $options['schedule_ai'] ?? true;
        $append = (bool) ($options['append'] ?? false);
        $assignment->loadMissing('practiceSet');

        if (! $assignment->practiceSet?->isWritten()) {
            throw new \InvalidArgumentException('This assignment is not a written homework sheet.');
        }

        // Completed is allowed so students can re-upload after seeing correct answers / AI misreads.
        if (! in_array($assignment->status, [
            SetAssignment::STATUS_ASSIGNED,
            SetAssignment::STATUS_IN_PROGRESS,
            SetAssignment::STATUS_COMPLETED,
        ], true)) {
            throw new \InvalidArgumentException('This assignment is no longer open for upload.');
        }

        $files = array_values(array_filter($files));

        if ($files === []) {
            throw new \InvalidArgumentException('Upload at least one photo or PDF of your completed work.');
        }

        if (count($files) > WrittenSubmissionLimits::MAX_FILES) {
            throw new \InvalidArgumentException(
                'Upload up to '.WrittenSubmissionLimits::MAX_FILES.' files (photos or PDFs).',
            );
        }

        $paths = [];
        $directory = 'written-submissions/'.$assignment->id;

        foreach ($files as $file) {
            $extension = strtolower($file->getClientOriginalExtension() ?: 'jpg');
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
                throw new \InvalidArgumentException('Only JPG, PNG, WEBP, or PDF files are allowed.');
            }

            $paths[] = $this->uploadOptimizer->storeOptimized($file, $directory);
        }

        $existing = WrittenSubmission::query()
            ->where('set_assignment_id', $assignment->id)
            ->whereIn('status', [
                WrittenSubmission::STATUS_UPLOADED,
                WrittenSubmission::STATUS_PROCESSING,
                WrittenSubmission::STATUS_GRADED,
                WrittenSubmission::STATUS_FAILED,
            ])
            ->latest('id')
            ->first();

        // Allow re-upload after graded so students can retry (e.g. after seeing correct answers
        // or when handwriting was misread). Latest upload replaces the previous one unless append is set.

        if ($existing) {
            if ($append) {
                $existingPaths = $existing->upload_paths ?? [];
                $paths = array_values(array_merge($existingPaths, $paths));

                if (count($paths) > WrittenSubmissionLimits::MAX_FILES) {
                    throw new \InvalidArgumentException(
                        'This upload would exceed '.WrittenSubmissionLimits::MAX_FILES.' pages total. Remove some pages or replace the upload instead.',
                    );
                }
            } else {
                foreach ($existing->upload_paths ?? [] as $oldPath) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $existing->items()->delete();
            $existing->update([
                'status' => WrittenSubmission::STATUS_UPLOADED,
                'upload_paths' => $paths,
                'score' => null,
                'max_score' => null,
                'ai_summary' => null,
                'handwriting_rating' => null,
                'teacher_remarks' => null,
                'grading_error' => null,
                'uploaded_at' => now(),
                'graded_at' => null,
            ]);
            $submission = $existing->fresh();
        } else {
            $submission = WrittenSubmission::create([
                'set_assignment_id' => $assignment->id,
                'status' => WrittenSubmission::STATUS_UPLOADED,
                'upload_paths' => $paths,
                'uploaded_at' => now(),
            ]);
        }

        if (in_array($assignment->status, [SetAssignment::STATUS_ASSIGNED, SetAssignment::STATUS_COMPLETED], true)) {
            $assignment->update(['status' => SetAssignment::STATUS_IN_PROGRESS]);
        }

        if ($scheduleAi) {
            WrittenSubmissionProgress::update($submission, 8, 'Queued');
            $this->scheduleGrading($submission);
        }

        return $submission;
    }

    /**
     * Teacher ticks each question correct/wrong; totals are calculated for weekly reports.
     *
     * @param  array{
     *     feedback?: string|null,
     *     remarks?: string|null,
     *     handwriting_rating?: string|null,
     *     items: list<array{question_id: int, is_correct: bool, note?: string|null}>
     * }  $data
     */
    public function applyManualGrade(SetAssignment $assignment, array $data): WrittenSubmission
    {
        $assignment->loadMissing(['practiceSet.questions']);

        if (! $assignment->practiceSet?->isWritten()) {
            throw new \InvalidArgumentException('This assignment is not a written homework sheet.');
        }

        if ($assignment->status === SetAssignment::STATUS_CANCELLED) {
            throw new \InvalidArgumentException('This assignment was cancelled.');
        }

        $questions = $assignment->practiceSet->questions->values();
        if ($questions->isEmpty()) {
            throw new \InvalidArgumentException('This sheet has no questions to mark.');
        }

        $itemRows = collect($data['items'] ?? []);
        if ($itemRows->count() !== $questions->count()) {
            throw new \InvalidArgumentException('Mark every question as correct or wrong.');
        }

        $byQuestionId = $itemRows->keyBy(fn (array $row) => (int) $row['question_id']);
        foreach ($questions as $question) {
            if (! $byQuestionId->has($question->id)) {
                throw new \InvalidArgumentException('Mark every question as correct or wrong.');
            }
        }

        $handwriting = isset($data['handwriting_rating']) ? trim((string) $data['handwriting_rating']) : '';
        if ($handwriting === '' || ! in_array($handwriting, WrittenSubmission::handwritingRatings(), true)) {
            throw new \InvalidArgumentException('Choose a handwriting rating (very good to very poor).');
        }

        $remarks = isset($data['remarks'])
            ? trim((string) $data['remarks'])
            : (isset($data['feedback']) ? trim((string) $data['feedback']) : '');
        $score = 0;
        $maxScore = $questions->count();

        $submission = WrittenSubmission::query()
            ->where('set_assignment_id', $assignment->id)
            ->latest('id')
            ->first();

        $previousExtracted = [];
        if ($submission) {
            $previousExtracted = $submission->items()
                ->get()
                ->keyBy('question_id')
                ->map(fn ($item) => $item->extracted_answer)
                ->all();
            $submission->items()->delete();
            $submission->update([
                'status' => WrittenSubmission::STATUS_GRADED,
                'score' => 0,
                'max_score' => $maxScore,
                'handwriting_rating' => $handwriting,
                'teacher_remarks' => $remarks !== '' ? $remarks : null,
                'grading_error' => null,
                'graded_at' => now(),
            ]);
        } else {
            $submission = WrittenSubmission::query()->create([
                'set_assignment_id' => $assignment->id,
                'status' => WrittenSubmission::STATUS_GRADED,
                'upload_paths' => [],
                'score' => 0,
                'max_score' => $maxScore,
                'handwriting_rating' => $handwriting,
                'teacher_remarks' => $remarks !== '' ? $remarks : null,
                'uploaded_at' => now(),
                'graded_at' => now(),
            ]);
        }

        foreach ($questions as $index => $question) {
            $row = $byQuestionId->get($question->id);
            $isCorrect = (bool) ($row['is_correct'] ?? false);
            $note = isset($row['note']) ? trim((string) $row['note']) : '';
            $itemScore = $isCorrect ? 1 : 0;
            $score += $itemScore;

            WrittenSubmissionItem::query()->create([
                'written_submission_id' => $submission->id,
                'question_id' => $question->id,
                'question_number' => $index + 1,
                'extracted_answer' => $previousExtracted[$question->id] ?? null,
                'step_feedback' => $note !== '' ? $note : ($isCorrect ? 'Correct' : 'Incorrect'),
                'score' => $itemScore,
                'max_score' => 1,
                'is_correct' => $isCorrect,
                'confidence' => null,
                'needs_review' => false,
            ]);
        }

        $submission->update([
            'score' => $score,
            'max_score' => $maxScore,
        ]);

        $assignment->update(['status' => SetAssignment::STATUS_COMPLETED]);

        $submission = $submission->fresh(['items']);
        WrittenSubmissionMailer::sendGraded($submission);

        $this->correctionQueue->syncFromWrittenSubmission($submission);

        return $submission;
    }

    public function runGrading(int $submissionId): bool
    {
        @set_time_limit(300);
        ignore_user_abort(true);

        $submission = WrittenSubmission::query()->find($submissionId);

        if (! $submission || ! in_array($submission->status, [
            WrittenSubmission::STATUS_UPLOADED,
            WrittenSubmission::STATUS_PROCESSING,
        ], true)) {
            return false;
        }

        if ($submission->status === WrittenSubmission::STATUS_PROCESSING) {
            $minutesProcessing = $submission->updated_at?->diffInMinutes(now()) ?? 0;

            if ($minutesProcessing < 3) {
                return false;
            }

            if ($minutesProcessing >= 15) {
                $submission->update([
                    'status' => WrittenSubmission::STATUS_FAILED,
                    'grading_error' => 'AI checking took too long for this upload. Your teacher can mark it manually — no need to upload again.',
                ]);
                WrittenSubmissionProgress::clear($submissionId);
                WrittenSubmissionMailer::sendCheckFailed($submission->fresh());

                return false;
            }
        }

        try {
            app(WrittenGradingService::class)->grade($submission);

            return true;
        } catch (\Throwable $exception) {
            Log::error('Written submission grading failed', [
                'submission_id' => $submissionId,
                'message' => $exception->getMessage(),
            ]);

            $submission->update([
                'status' => WrittenSubmission::STATUS_FAILED,
                'grading_error' => $exception->getMessage(),
            ]);

            WrittenSubmissionProgress::clear($submissionId);
            WrittenSubmissionMailer::sendCheckFailed($submission->fresh());

            return false;
        }
    }

    public function retryAiGrading(WrittenSubmission $submission): void
    {
        if (! in_array($submission->status, [
            WrittenSubmission::STATUS_FAILED,
            WrittenSubmission::STATUS_GRADED,
        ], true)) {
            throw new \InvalidArgumentException('Only graded or failed uploads can be sent for AI checking again.');
        }

        if ($submission->upload_paths === [] || $submission->upload_paths === null) {
            throw new \InvalidArgumentException('No uploaded files found for this submission.');
        }

        $submission->items()->delete();
        $submission->update([
            'status' => WrittenSubmission::STATUS_UPLOADED,
            'grading_error' => null,
            'score' => null,
            'max_score' => null,
            'ai_summary' => null,
            'handwriting_rating' => null,
            'teacher_remarks' => null,
            'graded_at' => null,
        ]);

        WrittenSubmissionProgress::update($submission, 8, 'Queued');
        $this->scheduleGrading($submission->fresh());
    }

    private function scheduleGrading(WrittenSubmission $submission): void
    {
        $submissionId = $submission->id;

        GradeWrittenSubmissionJob::dispatch($submissionId);

        if (config('queue.default') === 'sync') {
            return;
        }

        app()->terminating(static function () use ($submissionId): void {
            $submission = WrittenSubmission::query()->find($submissionId);

            if ($submission?->status === WrittenSubmission::STATUS_UPLOADED) {
                app(WrittenSubmissionService::class)->runGrading($submissionId);
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForAssignment(SetAssignment $assignment): ?array
    {
        $submission = WrittenSubmission::query()
            ->with([
                'items.question.options',
                'items.question.blankAnswer',
            ])
            ->where('set_assignment_id', $assignment->id)
            ->latest('id')
            ->first();

        if (! $submission) {
            return null;
        }

        $progress = WrittenSubmissionProgress::forSubmission($submission);

        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'score' => $submission->score,
            'max_score' => $submission->max_score,
            'ai_summary' => $submission->ai_summary,
            'handwriting_rating' => $submission->handwriting_rating,
            'handwriting_label' => $submission->handwritingLabel(),
            'teacher_remarks' => $submission->teacher_remarks,
            'grading_error' => $submission->grading_error,
            'uploaded_at' => $submission->uploaded_at?->toDateTimeString(),
            'graded_at' => $submission->graded_at?->toDateTimeString(),
            'uploaded_minutes_ago' => WrittenSubmissionProgress::checkingMinutes($submission),
            'checking_minutes' => in_array($submission->status, [
                WrittenSubmission::STATUS_UPLOADED,
                WrittenSubmission::STATUS_PROCESSING,
            ], true) ? WrittenSubmissionProgress::checkingMinutes($submission) : null,
            'grading_progress' => $progress['percent'],
            'grading_stage' => $progress['stage'],
            'upload_urls' => $submission->uploadUrls(),
            'upload_files' => $submission->uploadFiles(),
            'grading_page_files' => $submission->gradingPageFiles(),
            'can_retry' => in_array($submission->status, [
                WrittenSubmission::STATUS_GRADED,
                WrittenSubmission::STATUS_FAILED,
            ], true),
            'items' => $submission->items->map(function ($item) {
                $question = $item->question;
                $correctAnswer = null;

                if ($question) {
                    $correctAnswer = $question->isMcq()
                        ? $question->options->firstWhere('is_correct', true)?->option_text
                        : $question->blankAnswer?->correct_answer;
                }

                return [
                    'question_id' => $item->question_id,
                    'question_number' => $item->question_number,
                    'question_text' => $question?->question_text,
                    'extracted_answer' => $item->extracted_answer,
                    'correct_answer' => $correctAnswer,
                    'step_feedback' => $item->step_feedback,
                    'score' => $item->score,
                    'max_score' => $item->max_score,
                    'is_correct' => $item->is_correct,
                    'confidence' => $item->confidence,
                    'needs_review' => $item->needs_review,
                    'source_page' => $item->source_page,
                    'source_image_url' => $item->sourceImageUrl(),
                ];
            })->values()->all(),
        ];
    }

    public function retryAiQuestion(WrittenSubmission $submission, int $questionNumber): WrittenSubmissionItem
    {
        if ($submission->upload_paths === [] || $submission->upload_paths === null) {
            throw new \InvalidArgumentException('No uploaded files found for this submission.');
        }

        if (! in_array($submission->status, [
            WrittenSubmission::STATUS_GRADED,
            WrittenSubmission::STATUS_FAILED,
        ], true)) {
            throw new \InvalidArgumentException('Re-read is available after AI has finished or failed.');
        }

        return app(WrittenGradingService::class)->gradeQuestion($submission, $questionNumber);
    }
}
