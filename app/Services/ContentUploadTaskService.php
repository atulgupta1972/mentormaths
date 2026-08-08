<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\SyllabusChapter;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ContentOperationsMailer;
use Illuminate\Support\Facades\DB;

class ContentUploadTaskService
{
    public function __construct(
        private ContentRateCardService $rateCardService,
        private ContentDuplicateGuardService $duplicateGuard,
    ) {}

    /**
     * @param  list<int>  $chapterIds
     * @return array{tasks: list<ContentUploadTask>, email_sent: bool}
     */
    public function assignChapters(
        User $uploader,
        array $chapterIds,
        User $admin,
        ?int $amountOverrideInr = null,
        ?string $duplicateOverrideReason = null,
        ?string $adminNotes = null,
    ): array {
        $tasks = [];

        DB::transaction(function () use ($uploader, $chapterIds, $admin, $amountOverrideInr, $duplicateOverrideReason, $adminNotes, &$tasks) {
            foreach ($chapterIds as $chapterId) {
                $chapter = TextbookChapter::query()->findOrFail($chapterId);
                $duplicate = $this->duplicateGuard->check($chapter);

                if ($duplicate['blocked'] && ! $duplicateOverrideReason) {
                    throw new \InvalidArgumentException($duplicate['reason'] ?? 'Duplicate content blocked.');
                }

                $offered = $amountOverrideInr ?? $this->rateCardService->resolveAmountForChapter($chapter);

                if ($offered <= 0) {
                    throw new \InvalidArgumentException(
                        "No rate configured for chapter «{$chapter->title}». Set a rate in the matrix first.",
                    );
                }

                $task = ContentUploadTask::create([
                    'textbook_chapter_id' => $chapter->id,
                    'assigned_to_user_id' => $uploader->id,
                    'assigned_by_user_id' => $admin->id,
                    'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
                    'offered_amount_inr' => $offered,
                    'duplicate_override_reason' => $duplicate['blocked'] ? $duplicateOverrideReason : null,
                    'duplicate_override_by' => $duplicate['blocked'] ? $admin->id : null,
                    'admin_notes' => $adminNotes,
                ]);

                $tasks[] = $task;
            }
        });

        $emailSent = $tasks !== []
            ? ContentOperationsMailer::notifyAssigned($uploader, $tasks)
            : false;

        return [
            'tasks' => $tasks,
            'email_sent' => $emailSent,
        ];
    }

    /**
     * @param  list<int>  $syllabusChapterIds
     * @return array{tasks: list<ContentUploadTask>, email_sent: bool}
     */
    public function assignSyllabusChapters(
        Textbook $textbook,
        array $syllabusChapterIds,
        User $uploader,
        User $admin,
        ?int $amountOverrideInr = null,
        ?string $duplicateOverrideReason = null,
        ?string $adminNotes = null,
    ): array {
        $textbookChapterIds = [];

        foreach ($syllabusChapterIds as $syllabusChapterId) {
            $syllabusChapter = SyllabusChapter::query()->findOrFail($syllabusChapterId);
            $chapterNumber = $syllabusChapter->numericChapterNumber();

            $textbookChapter = TextbookChapter::query()->firstOrCreate(
                [
                    'textbook_id' => $textbook->id,
                    'syllabus_chapter_id' => $syllabusChapter->id,
                ],
                [
                    'chapter_number' => $chapterNumber,
                    'title' => $syllabusChapter->name,
                    'pdf_path' => null,
                    'status' => TextbookChapter::STATUS_DRAFT,
                    'created_by' => $admin->id,
                ],
            );

            if ($textbookChapter->title !== $syllabusChapter->name || (int) $textbookChapter->chapter_number !== $chapterNumber) {
                $textbookChapter->update([
                    'title' => $syllabusChapter->name,
                    'chapter_number' => $chapterNumber,
                ]);
            }

            $textbookChapterIds[] = $textbookChapter->id;
        }

        return $this->assignChapters(
            $uploader,
            $textbookChapterIds,
            $admin,
            $amountOverrideInr,
            $duplicateOverrideReason,
            $adminNotes,
        );
    }

    public function agree(ContentUploadTask $task, User $uploader): ContentUploadTask
    {
        if ($task->assigned_to_user_id !== $uploader->id) {
            throw new \InvalidArgumentException('You are not assigned to this task.');
        }

        if (! $task->isAwaitingAgreement()) {
            throw new \InvalidArgumentException('This task is not awaiting agreement.');
        }

        $task->update([
            'status' => ContentUploadTask::STATUS_IN_PROGRESS,
            'agreed_amount_inr' => $task->offered_amount_inr,
            'agreed_at' => now(),
        ]);

        ContentOperationsMailer::notifyAgreement($task->fresh(['assignee', 'textbookChapter.textbook']));

        return $task->fresh();
    }

    public function markUploaded(ContentUploadTask $task, User $uploader): ContentUploadTask
    {
        if ($task->assigned_to_user_id !== $uploader->id) {
            throw new \InvalidArgumentException('You are not assigned to this task.');
        }

        if (! in_array($task->status, [
            ContentUploadTask::STATUS_IN_PROGRESS,
            ContentUploadTask::STATUS_UPLOADED,
        ], true)) {
            throw new \InvalidArgumentException('Task is not in a state that allows upload completion.');
        }

        $chapter = $task->textbookChapter;

        if ($chapter->mcqWorksheetIds() === [] && empty($chapter->extraction_items)) {
            throw new \InvalidArgumentException(
                'Import MCQs for this chapter first (paste JSON on the textbook chapter page), approve questions, and save MCQ sets before marking upload complete.',
            );
        }

        if ($chapter->mcqWorksheetIds() === []) {
            throw new \InvalidArgumentException(
                'Save MCQ sets on the chapter page (tick Approved on questions, set plan, then Save MCQ sets) before marking upload complete.',
            );
        }

        $task->update(['status' => ContentUploadTask::STATUS_UPLOADED]);

        return $task->fresh();
    }

    public function submitForPublish(ContentUploadTask $task, User $uploader): ContentUploadTask
    {
        if ($task->assigned_to_user_id !== $uploader->id) {
            throw new \InvalidArgumentException('You are not assigned to this task.');
        }

        if ($task->status !== ContentUploadTask::STATUS_VERIFIED) {
            throw new \InvalidArgumentException('Complete verification before submitting for publish.');
        }

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

    public function publish(ContentUploadTask $task, User $admin): ContentUploadTask
    {
        if ($task->status !== ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH) {
            throw new \InvalidArgumentException('Task has not been submitted for publish.');
        }

        $task->update([
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => $admin->id,
        ]);

        return $task->fresh();
    }

    public function startReview(ContentUploadTask $task, User $uploader): ContentUploadTask
    {
        if ($task->assigned_to_user_id !== $uploader->id) {
            throw new \InvalidArgumentException('You are not assigned to this task.');
        }

        if ($task->status === ContentUploadTask::STATUS_IN_PROGRESS) {
            return $this->markUploaded($task, $uploader);
        }

        if (! in_array($task->status, [
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
        ], true)) {
            throw new \InvalidArgumentException('Save MCQ sets on the chapter page before starting review.');
        }

        return $task->fresh();
    }

    public function returnForReverification(
        ContentUploadTask $task,
        User $admin,
        ?string $reason = null,
    ): ContentUploadTask {
        if (! in_array($task->status, [
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_PUBLISHED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
        ], true)) {
            throw new \InvalidArgumentException(
                'Only submitted, verified, or in-progress verification tasks can be sent back for re-verification.',
            );
        }

        DB::transaction(function () use ($task, $admin, $reason) {
            app(ContentVerificationService::class)->resetAllVerification($task);

            $notes = trim((string) ($task->admin_notes ?? ''));
            if ($reason) {
                $stamp = now()->format('Y-m-d H:i');
                $notes = trim($notes."\n\n[Returned {$stamp} by {$admin->name}] {$reason}");
            }

            $task->update([
                'status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                'submitted_at' => null,
                'published_at' => null,
                'published_by' => null,
                'admin_notes' => $notes !== '' ? $notes : null,
            ]);
        });

        ContentOperationsMailer::notifyReturnedForReverification($task->fresh([
            'assignee',
            'textbookChapter.textbook.gradeLevel',
        ]));

        return $task->fresh();
    }
}
