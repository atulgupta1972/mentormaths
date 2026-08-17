<?php

namespace App\Services;

use App\Models\ContentRateCard;
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
        ?string $rateBasisOverride = null,
        ?string $duplicateOverrideReason = null,
        ?string $adminNotes = null,
    ): array {
        $tasks = [];

        DB::transaction(function () use ($uploader, $chapterIds, $admin, $amountOverrideInr, $rateBasisOverride, $duplicateOverrideReason, $adminNotes, &$tasks) {
            foreach ($chapterIds as $chapterId) {
                $chapter = TextbookChapter::query()->findOrFail($chapterId);
                $duplicate = $this->duplicateGuard->check($chapter);

                if ($duplicate['blocked'] && ! $duplicateOverrideReason) {
                    throw new \InvalidArgumentException($duplicate['reason'] ?? 'Duplicate content blocked.');
                }

                if ($amountOverrideInr !== null) {
                    $offered = $amountOverrideInr;
                    $rateBasis = $rateBasisOverride ?? ContentRateCard::BASIS_PER_SET;
                } else {
                    $resolved = $this->rateCardService->resolveRateForChapter($chapter);
                    $offered = $resolved['amount_inr'];
                    $rateBasis = $resolved['rate_basis'];
                }

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
                    'rate_basis' => $rateBasis,
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
        ?string $rateBasisOverride = null,
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
            $rateBasisOverride,
            $duplicateOverrideReason,
            $adminNotes,
        );
    }

    /**
     * Hand an unfinished chapter to another uploader. Existing PDF / questions stay on the chapter.
     *
     * @return array{task: ContentUploadTask, email_sent: bool, previous_name: string}
     */
    public function reassign(
        ContentUploadTask $task,
        User $newUploader,
        User $admin,
        ?string $note = null,
    ): array {
        $this->assertActiveUploader($newUploader);
        $previousName = $this->applyReassignment($task, $newUploader, $admin, $note);
        $fresh = $task->fresh(['assignee', 'textbookChapter.textbook.gradeLevel']);
        $emailSent = ContentOperationsMailer::notifyAssigned($newUploader, [$fresh]);

        return [
            'task' => $fresh,
            'email_sent' => $emailSent,
            'previous_name' => $previousName,
        ];
    }

    /**
     * @param  list<int>  $taskIds
     * @return array{moved_count: int, skipped_count: int, skipped: list<string>, email_sent: bool, previous_names: list<string>}
     */
    public function reassignMany(
        array $taskIds,
        User $newUploader,
        User $admin,
        ?string $note = null,
    ): array {
        $this->assertActiveUploader($newUploader);

        $ids = collect($taskIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values();

        $tasks = ContentUploadTask::query()
            ->with(['assignee:id,name,email', 'textbookChapter:id,title'])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        $moved = [];
        $skipped = [];
        $previousNames = [];

        foreach ($ids as $id) {
            $task = $tasks->get($id);
            if (! $task) {
                $skipped[] = "Chapter {$id} was not found.";
                continue;
            }

            try {
                $previousNames[] = $this->applyReassignment($task, $newUploader, $admin, $note);
                $moved[] = $task->fresh(['assignee', 'textbookChapter.textbook.gradeLevel']);
            } catch (\InvalidArgumentException $e) {
                $chapter = $task->textbookChapter?->title ?: "chapter {$task->id}";
                $skipped[] = "{$chapter}: {$e->getMessage()}";
            }
        }

        $emailSent = $moved !== []
            ? ContentOperationsMailer::notifyAssigned($newUploader, $moved)
            : false;

        return [
            'moved_count' => count($moved),
            'skipped_count' => count($skipped),
            'skipped' => $skipped,
            'email_sent' => $emailSent,
            'previous_names' => array_values(array_unique($previousNames)),
        ];
    }

    private function assertActiveUploader(User $uploader): void
    {
        if (! $uploader->isContentUploader() || ! $uploader->isActiveAccount()) {
            throw new \InvalidArgumentException('Selected user is not an active content uploader.');
        }
    }

    private function applyReassignment(
        ContentUploadTask $task,
        User $newUploader,
        User $admin,
        ?string $note = null,
    ): string {
        if ((int) $newUploader->id === (int) $task->assigned_to_user_id) {
            throw new \InvalidArgumentException('This chapter is already assigned to that uploader.');
        }

        if (! $task->canReassign()) {
            throw new \InvalidArgumentException(
                'This chapter cannot be reassigned once it is submitted, published, or cancelled.',
            );
        }

        if ($task->payment()->exists()) {
            throw new \InvalidArgumentException('This chapter already has a payment recorded.');
        }

        $task->loadMissing(['assignee', 'textbookChapter:id,title']);
        $previousName = $task->assignee?->name ?? 'previous uploader';

        $stamp = now()->format('Y-m-d H:i');
        $block = "[Reassigned {$stamp} by {$admin->name}] {$previousName} → {$newUploader->name}";
        if (filled(trim((string) $note))) {
            $block .= '. '.trim((string) $note);
        }

        $notes = trim((string) ($task->admin_notes ?? ''));
        $notes = trim($notes."\n\n".$block);

        $task->update([
            'assigned_to_user_id' => $newUploader->id,
            'assigned_by_user_id' => $admin->id,
            'admin_notes' => $notes !== '' ? $notes : null,
        ]);

        return $previousName;
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
        if (! in_array($task->status, [
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_VERIFIED,
        ], true)) {
            throw new \InvalidArgumentException(
                'Verify all questions first (or wait for uploader submit) before publishing.',
            );
        }

        $task->update([
            'status' => ContentUploadTask::STATUS_PUBLISHED,
            'submitted_at' => $task->submitted_at ?? now(),
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

    /**
     * @param  list<array{question_id: int, remark?: ?string, number?: int|null, question_text?: ?string}>  $items
     */
    public function returnForReverification(
        ContentUploadTask $task,
        User $admin,
        ?string $reason = null,
        array $items = [],
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

        $normalizedItems = collect($items)
            ->map(function (array $item) {
                $questionId = (int) ($item['question_id'] ?? 0);
                if ($questionId <= 0) {
                    return null;
                }

                return [
                    'question_id' => $questionId,
                    'number' => isset($item['number']) ? (int) $item['number'] : null,
                    'question_text' => isset($item['question_text'])
                        ? trim((string) $item['question_text'])
                        : null,
                    'remark' => trim((string) ($item['remark'] ?? '')),
                ];
            })
            ->filter()
            ->values()
            ->all();

        if ($normalizedItems === [] && ! filled(trim((string) $reason))) {
            throw new \InvalidArgumentException(
                'Select at least one question to send back, or add an overall remark.',
            );
        }

        DB::transaction(function () use ($task, $admin, $reason, $normalizedItems) {
            $verification = app(ContentVerificationService::class);

            if ($normalizedItems !== []) {
                $verification->resetVerificationForQuestions(
                    $task,
                    array_column($normalizedItems, 'question_id'),
                );
            } else {
                $verification->resetAllVerification($task);
            }

            $stamp = now()->format('Y-m-d H:i');
            $block = "[Returned {$stamp} by {$admin->name}]";
            if (filled(trim((string) $reason))) {
                $block .= ' '.trim((string) $reason);
            }

            if ($normalizedItems !== []) {
                $lines = [];
                foreach ($normalizedItems as $item) {
                    $label = $item['number'] ? "Q{$item['number']}" : "Question #{$item['question_id']}";
                    $remark = $item['remark'] !== '' ? $item['remark'] : 'Please re-check / fix';
                    $lines[] = "• {$label}: {$remark}";
                }
                $block .= "\n".implode("\n", $lines);
            }

            $notes = trim((string) ($task->admin_notes ?? ''));
            $notes = trim($notes."\n\n".$block);

            $task->update([
                'status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                'submitted_at' => null,
                'published_at' => null,
                'published_by' => null,
                'admin_notes' => $notes !== '' ? $notes : null,
            ]);
        });

        ContentOperationsMailer::notifyReturnedForReverification(
            $task->fresh([
                'assignee',
                'textbookChapter.textbook.gradeLevel',
            ]),
            $normalizedItems,
        );

        return $task->fresh();
    }
}
