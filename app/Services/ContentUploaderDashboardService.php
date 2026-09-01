<?php

namespace App\Services;

use App\Models\ContentQuestionCorrection;
use App\Models\ContentUploadTask;
use App\Models\User;
use Illuminate\Support\Collection;

class ContentUploaderDashboardService
{
    public function __construct(
        private TextbookChapterBookService $bookService,
        private ContentVerificationService $verificationService,
    ) {}

    /**
     * @return array{
     *     tasks: Collection<int, array<string, mixed>>,
     *     summary: array{upload_pending: int, review_pending: int, convert_pending: int, corrections_pending: int, gemini_pending: int, gemini_done: int, total_active: int},
     *     uploadPending: Collection<int, array<string, mixed>>,
     *     reviewPending: Collection<int, array<string, mixed>>,
     *     convertPending: Collection<int, array<string, mixed>>,
     *     correctionsPending: Collection<int, array<string, mixed>>,
     *     geminiPending: Collection<int, array<string, mixed>>,
     *     geminiDone: Collection<int, array<string, mixed>>
     * }
     */
    public function forUser(User $user): array
    {
        $taskModels = ContentUploadTask::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with(['textbookChapter.textbook.gradeLevel', 'textbookChapter.syllabusChapter.chapterHead'])
            ->latest()
            ->get();

        $progressByTask = $this->verificationService->progressForTasks($taskModels, $user);

        $tasks = $taskModels
            ->map(fn (ContentUploadTask $task) => $this->serializeTask(
                $task,
                $progressByTask[(int) $task->id] ?? null,
            ));

        $uploadPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'upload_pending')->values();
        $reviewPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'review_pending')->values();
        $convertPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'convert_pending')->values();
        $correctionsPending = $this->pendingCorrectionsForUser($user);

        $geminiPending = $tasks->filter(fn (array $task) =>
            ($task['gemini_progress']['can_gemini'] ?? false)
            && (int) ($task['gemini_progress']['pending'] ?? 0) > 0,
        )->values();

        $geminiDone = $tasks->filter(fn (array $task) =>
            ($task['gemini_progress']['can_gemini'] ?? false)
            && (int) ($task['gemini_progress']['pending'] ?? 0) === 0
            && (int) ($task['gemini_progress']['total'] ?? 0) > 0,
        )->values();

        return [
            'tasks' => $tasks,
            'summary' => [
                'upload_pending' => $uploadPending->count(),
                'review_pending' => $reviewPending->count(),
                'convert_pending' => $convertPending->count(),
                'corrections_pending' => $correctionsPending->count(),
                'gemini_pending' => $geminiPending->count(),
                'gemini_done' => $geminiDone->count(),
                'total_active' => $uploadPending->count() + $reviewPending->count() + $convertPending->count() + $geminiPending->count(),
            ],
            'uploadPending' => $uploadPending,
            'reviewPending' => $reviewPending,
            'convertPending' => $convertPending,
            'correctionsPending' => $correctionsPending,
            'geminiPending' => $geminiPending,
            'geminiDone' => $geminiDone,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function pendingCorrectionsForUser(User $user): Collection
    {
        return ContentQuestionCorrection::query()
            ->where('status', ContentQuestionCorrection::STATUS_PENDING)
            ->whereHas('task', fn ($query) => $query
                ->where('assigned_to_user_id', $user->id)
                ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED))
            ->with(['task.textbookChapter.textbook.gradeLevel'])
            ->latest()
            ->get()
            ->map(function (ContentQuestionCorrection $correction) {
                $chapter = $correction->task?->textbookChapter;

                return [
                    'id' => $correction->id,
                    'task_id' => $correction->content_upload_task_id,
                    'question_id' => $correction->question_id,
                    'question_number' => $correction->question_number,
                    'question_text' => $correction->question_text,
                    'remark' => $correction->remark,
                    'chapter_label' => $chapter
                        ? trim(
                            ($chapter->textbook?->gradeLevel?->name ? $chapter->textbook->gradeLevel->name.' · ' : '')
                            .($chapter->textbook?->name ? $chapter->textbook->name.' · ' : '')
                            .$chapter->displayChapterNumber().' — '.$chapter->displayTitle(),
                        )
                        : 'Chapter',
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>|null  $geminiProgress
     * @return array<string, mixed>
     */
    public function serializeTask(ContentUploadTask $task, ?array $geminiProgress = null): array
    {
        $chapter = $task->textbookChapter;
        $hasPdf = $chapter ? $this->bookService->hasStoredPdf($chapter) : false;

        return [
            'id' => $task->id,
            'work_type' => $task->work_type ?: ContentUploadTask::WORK_TYPE_MCQ_UPLOAD,
            'work_type_label' => $task->workTypeLabel(),
            'is_fill_blank_conversion' => $task->isFillBlankConversion(),
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'rate_basis' => $task->rate_basis,
            'rate_basis_label' => $task->rateBasisLabel(),
            'rate_description' => $task->rateDescription(),
            'calculation_label' => $task->calculationLabel(),
            'payable_amount_inr' => $task->payableAmountInr(),
            'payable_question_count' => $task->payableQuestionCount(),
            'skipped_question_count' => $task->skippedQuestionCount(),
            'uploaded_question_count' => $task->uploadedQuestionCount(),
            'offered_amount_inr' => $task->offered_amount_inr,
            'agreed_amount_inr' => $task->agreed_amount_inr,
            'agreed_at' => $task->agreed_at?->toIso8601String(),
            'submitted_at' => $task->submitted_at?->toIso8601String(),
            'bucket' => $task->uploaderBucket(),
            'bucket_label' => $task->uploaderBucketLabel(),
            'can_change_book' => $chapter && $task->assignee
                ? $this->bookService->uploaderCanChangeBook($chapter, $task->assignee)
                : false,
            'has_pdf' => $hasPdf,
            'gemini_progress' => $geminiProgress,
            'needs_gemini_check' => (bool) ($geminiProgress['can_gemini'] ?? false)
                && (int) ($geminiProgress['pending'] ?? 0) > 0,
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->displayChapterNumber(),
                'title' => $chapter->displayTitle(),
                'syllabus_label' => $chapter->displaySyllabusLabel(),
                'chapter_head_name' => $chapter->displayChapterHeadName(),
                'textbook_name' => $chapter->textbook?->name,
                'textbook_code' => $chapter->textbook?->code,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'pdf_url' => $chapter->pdfUrl(),
                'has_pdf' => $hasPdf,
            ] : null,
        ];
    }
}
