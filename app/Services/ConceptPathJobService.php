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

    public function assign(
        TextbookChapter $chapter,
        User $uploader,
        User $admin,
        ?int $amountOverrideInr = null,
        ?string $adminNotes = null,
        bool $notify = true,
    ): ContentUploadTask {
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

        // PDF may be missing — assignee uploads it, then builds concepts and Runs.

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

        if ($notify) {
            ContentOperationsMailer::notifyAssigned($uploader, [$task->fresh(['textbookChapter.textbook.gradeLevel'])]);
        }

        return $task->fresh(['assignee', 'textbookChapter.textbook.gradeLevel']);
    }

    /**
     * Assign several chapters to one uploader in one go (single email).
     *
     * @param  list<TextbookChapter>  $chapters
     * @return list<ContentUploadTask>
     */
    public function assignMany(
        array $chapters,
        User $uploader,
        User $admin,
        ?int $amountOverrideInr = null,
        ?string $adminNotes = null,
    ): array {
        if ($chapters === []) {
            throw new InvalidArgumentException('Select at least one chapter to assign.');
        }

        $tasks = [];
        foreach ($chapters as $chapter) {
            $tasks[] = $this->assign($chapter, $uploader, $admin, $amountOverrideInr, $adminNotes, notify: false);
        }

        ContentOperationsMailer::notifyAssigned(
            $uploader,
            collect($tasks)->map(fn (ContentUploadTask $task) => $task->fresh(['textbookChapter.textbook.gradeLevel']))->all(),
        );

        return $tasks;
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

        return $this->markSubmitted($task, notify: true);
    }

    /**
     * An approved concept path is finished work. Put the open job on the
     * admin dashboard (Submitted) so it is not buried with unfinished chapters.
     */
    public function submitOpenJobForApprovedChapter(TextbookChapter $chapter, bool $notify = true): ?ContentUploadTask
    {
        if ($chapter->concept_path_status !== ConceptPathStatus::APPROVED) {
            return null;
        }

        $task = $this->openTaskForChapter($chapter);

        if (! $task) {
            return null;
        }

        return $this->markSubmitted($task, $notify);
    }

    /**
     * Chapters already approved before this rule still sit as "in progress".
     * Opening the dashboard moves those jobs to submitted without emailing again.
     */
    public function submitApprovedJobsStillOpen(): int
    {
        $tasks = ContentUploadTask::query()
            ->where('work_type', ContentUploadTask::WORK_TYPE_CONCEPT_PATH_BUILD)
            ->whereNotIn('status', [
                ContentUploadTask::STATUS_PENDING_AGREEMENT,
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_PUBLISHED,
                ContentUploadTask::STATUS_CANCELLED,
            ])
            ->whereHas('textbookChapter', fn ($query) => $query->where('concept_path_status', ConceptPathStatus::APPROVED))
            ->get();

        $moved = 0;
        foreach ($tasks as $task) {
            $fresh = $this->markSubmitted($task, notify: false);
            if ($fresh->status === ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH) {
                $moved++;
            }
        }

        return $moved;
    }

    /**
     * Send one concept card back to the assigned uploader with admin remarks.
     * Un-approves the path so the uploader can regenerate / add figures and re-run.
     */
    public function returnCard(
        TextbookChapter $chapter,
        User $admin,
        int $cardIndex,
        string $remark,
        bool $notify = true,
    ): ContentUploadTask {
        $remark = trim($remark);
        if ($remark === '') {
            throw new InvalidArgumentException('Add a short remark so the uploader knows what to fix.');
        }

        $task = $this->openTaskForChapter($chapter);
        if (! $task || ! $task->assigned_to_user_id) {
            throw new InvalidArgumentException('Assign a concept builder for this chapter before returning a card.');
        }

        if ($task->status === ContentUploadTask::STATUS_PENDING_AGREEMENT) {
            throw new InvalidArgumentException('Uploader has not agreed the concept-builder rate yet.');
        }

        $items = is_array($chapter->concept_path_items) ? $chapter->concept_path_items : [];
        $cards = is_array($items['cards'] ?? null) ? $items['cards'] : [];

        if (! isset($cards[$cardIndex]) || ! is_array($cards[$cardIndex])) {
            throw new InvalidArgumentException('That concept card was not found.');
        }

        $card = $cards[$cardIndex];
        $step = (int) ($card['step'] ?? ($cardIndex + 1));
        $title = trim((string) ($card['title'] ?? ''));

        $cards[$cardIndex]['admin_return_remark'] = $remark;
        $cards[$cardIndex]['admin_returned_at'] = now()->toIso8601String();
        $items['cards'] = array_values($cards);

        $stamp = now()->format('Y-m-d H:i');
        $label = $title !== '' ? "Step {$step} — {$title}" : "Step {$step}";
        $block = "[Concept card returned {$stamp} by {$admin->name}]\n• {$label}: {$remark}";
        $notes = trim((string) ($task->admin_notes ?? ''));
        $notes = trim($notes."\n\n".$block);

        $chapter->update([
            'concept_path_items' => $items,
            'concept_path_status' => ConceptPathStatus::DRAFT,
            'concept_path_approved_at' => null,
            'concept_path_approved_by' => null,
        ]);

        $task->update([
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'submitted_at' => null,
            'published_at' => null,
            'published_by' => null,
            'admin_notes' => $notes !== '' ? $notes : null,
        ]);

        $fresh = $task->fresh([
            'assignee',
            'textbookChapter.textbook.gradeLevel',
        ]);

        if ($notify && $fresh) {
            ContentOperationsMailer::notifyConceptPathReturned($fresh, [[
                'step' => $step,
                'title' => $title !== '' ? $title : null,
                'remark' => $remark,
            ]]);
        }

        return $fresh ?? $task;
    }

    private function markSubmitted(ContentUploadTask $task, bool $notify): ContentUploadTask
    {
        if (in_array($task->status, [
            ContentUploadTask::STATUS_PENDING_AGREEMENT,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
            ContentUploadTask::STATUS_CANCELLED,
        ], true)) {
            return $task;
        }

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => $task->submitted_at ?? now(),
        ]);

        $fresh = $task->fresh([
            'assignee',
            'textbookChapter.textbook.gradeLevel',
        ]);

        if ($notify && $fresh) {
            ContentOperationsMailer::notifySubmittedForPublish($fresh);
        }

        return $fresh ?? $task;
    }
}
