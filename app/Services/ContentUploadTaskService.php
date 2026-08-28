<?php

namespace App\Services;

use App\Models\ContentQuestionCorrection;
use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\ContentOperationsMailer;
use Illuminate\Support\Collection;
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
                    'work_type' => ContentUploadTask::WORK_TYPE_MCQ_UPLOAD,
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

        $this->assertChapterHasPdf($chapter);

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

        $this->assertChapterHasPdf($task->textbookChapter);

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

        $this->assertChapterHasPdf($task->textbookChapter);

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
     * Latest non-cancelled upload task that owns this live question.
     */
    public function taskForQuestion(Question $question): ?ContentUploadTask
    {
        return $this->returnableTaskForQuestion($question);
    }

    /**
     * Best assigned task that can receive a single-sum return / resend.
     */
    public function returnableTaskForQuestion(Question $question): ?ContentUploadTask
    {
        $question->loadMissing(['worksheets', 'topic:id,syllabus_chapter_id']);

        $chapterIds = $this->candidateChapterIdsForQuestion($question);
        if ($chapterIds === []) {
            return null;
        }

        $candidates = ContentUploadTask::query()
            ->with(['assignee:id,name,email', 'textbookChapter.textbook.gradeLevel'])
            ->whereIn('textbook_chapter_id', $chapterIds)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->latest()
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $chapter = TextbookChapter::query()->whereIn('id', $chapterIds)->get()
            ->first(function (TextbookChapter $chapter) use ($question) {
                $owned = [
                    ...$chapter->mcqWorksheetIds(),
                    ...$chapter->fillBlankWorksheetIds(),
                    ...$chapter->writtenWorksheetIds(),
                ];
                $qWs = $question->worksheets->pluck('id')->map(fn ($id) => (int) $id)->all();

                return array_intersect($owned, $qWs) !== [];
            });

        return $this->pickTaskForQuestion($candidates, $question, $chapter)
            ?? $candidates->first(fn (ContentUploadTask $task) => filled($task->assigned_to_user_id))
            ?? $candidates->first();
    }

    /**
     * @return list<int>
     */
    private function candidateChapterIdsForQuestion(Question $question): array
    {
        $worksheetIds = $question->worksheets->pluck('id')->map(fn ($id) => (int) $id)->all();
        $chapterIds = $this->chaptersForWorksheetIds($worksheetIds)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $syllabusChapterId = (int) ($question->topic?->syllabus_chapter_id ?? 0);
        if ($syllabusChapterId > 0) {
            $bySyllabus = TextbookChapter::query()
                ->where('syllabus_chapter_id', $syllabusChapterId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $chapterIds = array_values(array_unique([...$chapterIds, ...$bySyllabus]));
        }

        return $chapterIds;
    }

    /**
     * @param  list<int>  $questionIds
     * @return Collection<int, ContentUploadTask>
     */
    public function tasksKeyedByQuestionId(array $questionIds): Collection
    {
        $ids = collect($questionIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $questions = Question::query()
            ->with(['worksheets', 'topic:id,syllabus_chapter_id'])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');

        return $ids->mapWithKeys(function (int $questionId) use ($questions) {
            $question = $questions->get($questionId);
            if (! $question) {
                return [];
            }

            $task = $this->returnableTaskForQuestion($question);

            return $task ? [$questionId => $task] : [];
        });
    }

    /**
     * Prefer a returnable assigned task — fill-blank conversion when the sum lives on a FIB set.
     *
     * @param  Collection<int, ContentUploadTask>  $chapterTasks
     */
    private function pickTaskForQuestion(
        Collection $chapterTasks,
        ?Question $question,
        ?TextbookChapter $chapter,
    ): ?ContentUploadTask {
        if ($chapterTasks->isEmpty()) {
            return null;
        }

        $onFillBlankSet = false;
        if ($question && $chapter) {
            $fillIds = $chapter->fillBlankWorksheetIds();
            $onFillBlankSet = $fillIds !== [] && $question->worksheets
                ->contains(fn ($ws) => in_array((int) $ws->id, $fillIds, true));
        }

        $ranked = $chapterTasks->sortByDesc(function (ContentUploadTask $task) use ($onFillBlankSet) {
            $score = 0;
            if ($this->canReturnSpecificQuestions($task)) {
                $score += 100;
            }
            if ($task->assigned_to_user_id) {
                $score += 40;
            }
            if ($onFillBlankSet && $task->isFillBlankConversion()) {
                $score += 20;
            }
            if (! $onFillBlankSet && ! $task->isFillBlankConversion()) {
                $score += 20;
            }

            return $score;
        })->values();

        return $ranked->first(fn (ContentUploadTask $task) => $this->canReturnSpecificQuestions($task) && $task->assigned_to_user_id)
            ?? $ranked->first(fn (ContentUploadTask $task) => $this->canReturnSpecificQuestions($task))
            ?? $ranked->first();
    }

    /**
     * @return array{
     *     can_return_to_uploader: bool,
     *     content_task_id: int|null,
     *     uploader_name: ?string,
     *     chapter_label: ?string
     * }
     */
    public function uploaderReturnPayload(?ContentUploadTask $task): array
    {
        if (! $task) {
            return [
                'can_return_to_uploader' => false,
                'content_task_id' => null,
                'uploader_name' => null,
                'chapter_label' => null,
            ];
        }

        $chapter = $task->textbookChapter;
        $grade = $chapter?->textbook?->gradeLevel?->name;
        $chapterLabel = $chapter
            ? trim(($grade ? $grade.' ' : '').'Ch '.$chapter->chapter_number.' — '.$chapter->title)
            : null;

        return [
            'can_return_to_uploader' => $this->canReturnSpecificQuestions($task)
                && filled($task->assigned_to_user_id),
            'content_task_id' => $task->id,
            'uploader_name' => $task->assignee?->name,
            'chapter_label' => $chapterLabel,
        ];
    }

    public function remarkForHelpIssue(string $issue, ?string $remark = null): string
    {
        $extra = trim((string) $remark);

        $base = match ($issue) {
            'wrong_answer' => 'Wrong answer — please correct the key/options',
            'incomplete' => 'Sum is incomplete — please complete the question',
            default => $extra !== '' ? $extra : 'Please fix this question',
        };

        if ($issue !== 'other' && $extra !== '') {
            return $base.': '.$extra;
        }

        return $base;
    }

    public function returnHelpRequestQuestion(
        Question $question,
        User $admin,
        string $issue,
        ?string $remark = null,
        string $adminContext = 'Student asked for teacher help. Admin found a content issue — fix only this sum.',
        string $source = ContentQuestionCorrection::SOURCE_HELP_REQUEST,
    ): ContentUploadTask {
        $task = $this->returnableTaskForQuestion($question);

        if (! $task) {
            throw new \InvalidArgumentException('No content uploader is assigned to this question. Edit it yourself.');
        }

        if (! $task->assigned_to_user_id) {
            throw new \InvalidArgumentException('No content uploader is assigned to this chapter. Edit it yourself.');
        }

        if (! $this->canReturnSpecificQuestions($task)) {
            throw new \InvalidArgumentException('This chapter cannot be sent back to the uploader yet.');
        }

        $question->loadMissing('worksheets');
        $sortOrder = $question->worksheets->first()?->pivot?->sort_order;

        return $this->returnForReverification(
            $task,
            $admin,
            $adminContext,
            [[
                'question_id' => (int) $question->id,
                'number' => $sortOrder ? (int) $sortOrder : null,
                'question_text' => $question->question_text,
                'remark' => $this->remarkForHelpIssue($issue, $remark),
            ]],
            notifyNow: true,
            source: $source,
        );
    }

    public function canReturnSpecificQuestions(ContentUploadTask $task): bool
    {
        return in_array($task->status, [
            ContentUploadTask::STATUS_PENDING_AGREEMENT,
            ContentUploadTask::STATUS_IN_PROGRESS,
            ContentUploadTask::STATUS_UPLOADED,
            ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ContentUploadTask::STATUS_VERIFIED,
            ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            ContentUploadTask::STATUS_PUBLISHED,
        ], true);
    }

    /**
     * @param  list<int>  $worksheetIds
     * @return Collection<int, TextbookChapter>
     */
    private function chaptersForWorksheetIds(array $worksheetIds): Collection
    {
        if ($worksheetIds === []) {
            return collect();
        }

        $direct = TextbookChapter::query()
            ->where(function ($query) use ($worksheetIds) {
                $query->whereIn('mcq_worksheet_id', $worksheetIds)
                    ->orWhereIn('fill_blank_worksheet_id', $worksheetIds)
                    ->orWhereIn('written_worksheet_id', $worksheetIds);
            })
            ->get();

        $fromJson = TextbookChapter::query()
            ->where(function ($query) {
                $query->whereNotNull('mcq_worksheet_ids')
                    ->orWhereNotNull('fill_blank_worksheet_ids')
                    ->orWhereNotNull('written_worksheet_ids');
            })
            ->get()
            ->filter(function (TextbookChapter $chapter) use ($worksheetIds) {
                $owned = [
                    ...$chapter->mcqWorksheetIds(),
                    ...$chapter->fillBlankWorksheetIds(),
                    ...$chapter->writtenWorksheetIds(),
                ];

                return array_intersect($owned, $worksheetIds) !== [];
            });

        return $direct->concat($fromJson)->unique('id')->values();
    }

    /**
     * @param  list<array{question_id: int, remark?: ?string, number?: int|null, question_text?: ?string}>  $items
     */
    public function returnForReverification(
        ContentUploadTask $task,
        User $admin,
        ?string $reason = null,
        array $items = [],
        bool $notifyNow = true,
        string $source = ContentQuestionCorrection::SOURCE_ADMIN_RETURN,
    ): ContentUploadTask {
        $hasSpecificItems = collect($items)->contains(fn (array $item) => (int) ($item['question_id'] ?? 0) > 0);

        $allowed = $hasSpecificItems
            ? [
                ContentUploadTask::STATUS_PENDING_AGREEMENT,
                ContentUploadTask::STATUS_IN_PROGRESS,
                ContentUploadTask::STATUS_UPLOADED,
                ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                ContentUploadTask::STATUS_VERIFIED,
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_PUBLISHED,
            ]
            : [
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_VERIFIED,
                ContentUploadTask::STATUS_PUBLISHED,
                ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
            ];

        if (! in_array($task->status, $allowed, true)) {
            throw new \InvalidArgumentException(
                'This chapter can be sent back for a re-check after upload, submit, or publish.',
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

        DB::transaction(function () use ($task, $admin, $reason, $normalizedItems, $source) {
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

                    $existing = ContentQuestionCorrection::query()
                        ->where('content_upload_task_id', $task->id)
                        ->where('question_id', $item['question_id'])
                        ->latest('id')
                        ->first();

                    $payload = [
                        'question_number' => $item['number'],
                        'question_text' => $item['question_text'],
                        'remark' => $remark,
                        'source' => $source,
                        'requested_by' => $admin->id,
                        'status' => ContentQuestionCorrection::STATUS_PENDING,
                        'completed_at' => null,
                        'notified_at' => null,
                    ];

                    if ($existing) {
                        $existing->update($payload);
                    } else {
                        ContentQuestionCorrection::query()->create([
                            'content_upload_task_id' => $task->id,
                            'question_id' => $item['question_id'],
                            ...$payload,
                        ]);
                    }
                }
                $block .= "\n".implode("\n", $lines);
            }

            $notes = trim((string) ($task->admin_notes ?? ''));
            $notes = trim($notes."\n\n".$block);

            $task->update([
                'status' => ContentUploadTask::STATUS_VERIFICATION_IN_PROGRESS,
                'submitted_at' => null,
                'admin_notes' => $notes !== '' ? $notes : null,
            ]);
        });

        if ($notifyNow) {
            $this->notifyReturned($task, $normalizedItems);
        }

        return $task->fresh();
    }

    /**
     * @param  list<array{question_id: int, remark?: string, number?: int|null, question_text?: ?string}>  $items
     */
    public function notifyReturned(ContentUploadTask $task, array $items = []): bool
    {
        $sent = ContentOperationsMailer::notifyReturnedForReverification(
            $task->fresh([
                'assignee',
                'textbookChapter.textbook.gradeLevel',
            ]),
            $items,
        );

        if ($sent && $items !== []) {
            ContentQuestionCorrection::query()
                ->where('content_upload_task_id', $task->id)
                ->where('status', ContentQuestionCorrection::STATUS_PENDING)
                ->whereIn('question_id', array_column($items, 'question_id'))
                ->whereNull('notified_at')
                ->update(['notified_at' => now()]);
        }

        return $sent;
    }

    public function startQuestionCorrection(ContentQuestionCorrection $correction, User $uploader): ContentUploadTask
    {
        $task = $correction->task;

        if (! $task || $task->assigned_to_user_id !== $uploader->id) {
            throw new \InvalidArgumentException('You are not assigned to this correction.');
        }

        if (! $correction->isPending()) {
            throw new \InvalidArgumentException('This sum is already corrected.');
        }

        if (! $correction->notified_at) {
            $this->notifyReturned($task, [[
                'question_id' => (int) $correction->question_id,
                'number' => $correction->question_number,
                'question_text' => $correction->question_text,
                'remark' => $correction->remark,
            ]]);
            $correction->refresh();
        }

        if (in_array($task->status, [
            ContentUploadTask::STATUS_IN_PROGRESS,
            ContentUploadTask::STATUS_UPLOADED,
        ], true)) {
            $this->startReview($task, $uploader);
        }

        return $task->fresh();
    }

    public function completeQuestionCorrection(ContentUploadTask $task, int $questionId): void
    {
        ContentQuestionCorrection::query()
            ->where('content_upload_task_id', $task->id)
            ->where('question_id', $questionId)
            ->where('status', ContentQuestionCorrection::STATUS_PENDING)
            ->update([
                'status' => ContentQuestionCorrection::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
    }

    /**
     * Close every pending uploader correction row for this sum (any chapter task).
     */
    public function completeAllPendingCorrectionsForQuestion(int $questionId): int
    {
        return ContentQuestionCorrection::query()
            ->where('question_id', $questionId)
            ->where('status', ContentQuestionCorrection::STATUS_PENDING)
            ->update([
                'status' => ContentQuestionCorrection::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
    }

    private function assertChapterHasPdf(?TextbookChapter $chapter): void
    {
        if (! $chapter || ! filled($chapter->pdf_path)) {
            throw new \InvalidArgumentException(
                'Upload the chapter PDF first (open the chapter editor and attach the textbook PDF).',
            );
        }
    }
}
