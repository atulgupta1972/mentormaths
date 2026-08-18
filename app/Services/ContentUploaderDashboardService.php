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
    ) {}

    /**
     * @return array{
     *     tasks: Collection<int, array<string, mixed>>,
     *     summary: array{upload_pending: int, review_pending: int, corrections_pending: int, total_active: int},
     *     uploadPending: Collection<int, array<string, mixed>>,
     *     reviewPending: Collection<int, array<string, mixed>>,
     *     correctionsPending: Collection<int, array<string, mixed>>
     * }
     */
    public function forUser(User $user): array
    {
        $tasks = ContentUploadTask::query()
            ->where('assigned_to_user_id', $user->id)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->with(['textbookChapter.textbook.gradeLevel'])
            ->latest()
            ->get()
            ->map(fn (ContentUploadTask $task) => $this->serializeTask($task));

        $uploadPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'upload_pending')->values();
        $reviewPending = $tasks->filter(fn (array $task) => $task['bucket'] === 'review_pending')->values();
        $correctionsPending = $this->pendingCorrectionsForUser($user);

        return [
            'tasks' => $tasks,
            'summary' => [
                'upload_pending' => $uploadPending->count(),
                'review_pending' => $reviewPending->count(),
                'corrections_pending' => $correctionsPending->count(),
                'total_active' => $uploadPending->count() + $reviewPending->count(),
            ],
            'uploadPending' => $uploadPending,
            'reviewPending' => $reviewPending,
            'correctionsPending' => $correctionsPending,
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
                            .'Ch '.$chapter->chapter_number.' — '.$chapter->title,
                        )
                        : 'Chapter',
                ];
            })
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeTask(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;
        $hasPdf = $chapter ? $this->bookService->hasStoredPdf($chapter) : false;

        return [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'rate_basis' => $task->rate_basis,
            'rate_basis_label' => $task->rateBasisLabel(),
            'rate_description' => $task->rateDescription(),
            'payable_amount_inr' => $task->payableAmountInr(),
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
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'textbook_name' => $chapter->textbook?->name,
                'textbook_code' => $chapter->textbook?->code,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'pdf_url' => $chapter->pdfUrl(),
                'has_pdf' => $hasPdf,
            ] : null,
        ];
    }
}
