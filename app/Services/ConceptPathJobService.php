<?php

namespace App\Services;

use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ConceptPathStatus;
use App\Support\ContentOperationsMailer;
use InvalidArgumentException;

class ConceptPathJobService
{
    public const DEFAULT_AMOUNT_INR = 50;

    public function __construct(
        private TextbookChapterBookService $bookService,
    ) {}

    public function assign(
        TextbookChapter $chapter,
        User $uploader,
        User $admin,
        ?int $amountOverrideInr = null,
        ?string $adminNotes = null,
    ): ContentUploadTask {
        if (! $this->bookService->hasStoredPdf($chapter)) {
            throw new InvalidArgumentException('Upload the chapter PDF before assigning concept builder.');
        }

        $existing = ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('work_type', ContentUploadTask::WORK_TYPE_CONCEPT_PATH_BUILD)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException('Concept builder is already assigned for this chapter.');
        }

        $offered = $amountOverrideInr ?? self::DEFAULT_AMOUNT_INR;

        if ($offered <= 0) {
            throw new InvalidArgumentException('Concept builder rate must be at least ₹1.');
        }

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_CONCEPT_PATH_BUILD,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'rate_basis' => ContentRateCard::BASIS_PER_SET,
            'offered_amount_inr' => $offered,
            'admin_notes' => $adminNotes,
        ]);

        ContentOperationsMailer::notifyAssigned($uploader, [$task->fresh(['textbookChapter.textbook.gradeLevel'])]);

        return $task->fresh(['assignee', 'textbookChapter.textbook.gradeLevel']);
    }

    /**
     * Block starting/agreeing another concept job while one is still open.
     */
    public function assertCanStart(User $uploader, ?ContentUploadTask $except = null): void
    {
        $query = ContentUploadTask::query()
            ->where('assigned_to_user_id', $uploader->id)
            ->where('work_type', ContentUploadTask::WORK_TYPE_CONCEPT_PATH_BUILD)
            ->whereNotIn('status', [
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_PUBLISHED,
                ContentUploadTask::STATUS_CANCELLED,
            ]);

        if ($except) {
            $query->where('id', '!=', $except->id);
        }

        $open = $query->with('textbookChapter.textbook')->first();

        if (! $open) {
            return;
        }

        $chapter = $open->textbookChapter;
        $label = $chapter
            ? trim(($chapter->textbook?->name ? $chapter->textbook->name.' · ' : '').$chapter->displayChapterNumber().' — '.$chapter->displayTitle())
            : 'another chapter';

        throw new InvalidArgumentException(
            "Finish the open concept builder for {$label} (agree → build → approve → Run) before starting the next one.",
        );
    }

    public function openTaskForChapter(TextbookChapter $chapter): ?ContentUploadTask
    {
        return ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('work_type', ContentUploadTask::WORK_TYPE_CONCEPT_PATH_BUILD)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->latest('id')
            ->first();
    }

    public function assertMcqMaySubmit(TextbookChapter $chapter): void
    {
        $task = $this->openTaskForChapter($chapter);

        if (! $task) {
            return;
        }

        if (in_array($task->status, [
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true)) {
            return;
        }

        throw new InvalidArgumentException(
            'Concept builder for this chapter is still open (₹'.$task->rateUnitInr()
            .' job). Approve the path and Run concepts to the end before submitting MCQs for publish.',
        );
    }

    /**
     * After a full Run (not single-card preview), complete the assigned concept job.
     */
    public function completeAfterFullRun(TextbookChapter $chapter, User $user): ?ContentUploadTask
    {
        if ($chapter->concept_path_status !== ConceptPathStatus::APPROVED) {
            return null;
        }

        $task = $this->openTaskForChapter($chapter);

        if (! $task || (int) $task->assigned_to_user_id !== (int) $user->id) {
            return null;
        }

        if (in_array($task->status, [
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
            ContentUploadTask::STATUS_CANCELLED,
        ], true)) {
            return $task;
        }

        if ($task->status === ContentUploadTask::STATUS_PENDING_AGREEMENT) {
            throw new InvalidArgumentException('Agree the concept-builder rate before running.');
        }

        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];
        $included = array_values(array_filter(
            $cards,
            fn ($card) => is_array($card) && ($card['approved'] ?? true),
        ));

        if ($included === []) {
            throw new InvalidArgumentException('Approve at least one concept card before completing.');
        }

        $chapter->update([
            'concept_path_last_played_at' => now(),
        ]);

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        ContentOperationsMailer::notifySubmittedForPublish($task->fresh([
            'assignee',
            'textbookChapter.textbook.gradeLevel',
        ]));

        return $task->fresh();
    }
}
