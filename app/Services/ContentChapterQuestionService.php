<?php

namespace App\Services;

use App\Models\ContentQuestionDeleteRequest;
use App\Models\TextbookChapter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ContentChapterQuestionService
{
    public function __construct(
        private ContentTextbookAccessService $access,
        private TextbookChapterMcqImportService $mcqImport,
        private TextbookChapterPublishService $publishService,
    ) {}

    /**
     * @return array{added_count: int, total_count: int, diagram_count: int}
     */
    public function appendJson(TextbookChapter $chapter, string $json, User $user): array
    {
        $before = count($chapter->extraction_items ?? []);
        $result = $this->mcqImport->append($chapter, $json);
        $chapter = $result['chapter'];
        $newItems = array_slice($chapter->extraction_items ?? [], $before);

        $this->publishNewItemsIfLive($chapter, $newItems, $user);

        return [
            'added_count' => $result['added_count'],
            'total_count' => $result['total_count'],
            'diagram_count' => 0,
        ];
    }

    /**
     * @return array{added_count: int, total_count: int, diagram_count: int}
     */
    public function appendZip(TextbookChapter $chapter, UploadedFile $zip, User $user): array
    {
        $before = count($chapter->extraction_items ?? []);
        $result = $this->mcqImport->appendZip($chapter, $zip);
        $chapter = $result['chapter'];
        $newItems = array_slice($chapter->extraction_items ?? [], $before);

        $this->publishNewItemsIfLive($chapter, $newItems, $user);

        return [
            'added_count' => $result['added_count'],
            'total_count' => $result['total_count'],
            'diagram_count' => $result['diagram_count'],
        ];
    }

    public function deleteItem(TextbookChapter $chapter, int $itemIndex, User $actor, bool $asAdmin = false): void
    {
        $task = $this->access->assignedTask($actor, $chapter);
        if (! $asAdmin && (! $task || $task->isLockedForUploaderDelete())) {
            throw new InvalidArgumentException('This chapter is published. Ask admin to delete the question.');
        }

        $items = $chapter->extraction_items ?? [];
        if (! isset($items[$itemIndex])) {
            throw new InvalidArgumentException('Question not found.');
        }

        DB::transaction(function () use ($chapter, $itemIndex, $items) {
            $this->publishService->removePublishedMcqAtIndex($chapter, $itemIndex);

            $this->mcqImport->deleteStagingPathForItem($items[$itemIndex] ?? []);
            unset($items[$itemIndex]);
            $items = array_values($items);

            $plan = $this->shrinkSetPlanAfterDelete($chapter->mcq_set_plan ?? [], $itemIndex, count($items));

            $chapter->update([
                'extraction_items' => $items,
                'mcq_set_plan' => $plan,
            ]);

            ContentQuestionDeleteRequest::query()
                ->where('textbook_chapter_id', $chapter->id)
                ->where('item_index', $itemIndex)
                ->where('status', ContentQuestionDeleteRequest::STATUS_PENDING)
                ->update([
                    'status' => ContentQuestionDeleteRequest::STATUS_APPROVED,
                    'reviewed_at' => now(),
                ]);
        });
    }

    public function requestDelete(TextbookChapter $chapter, int $itemIndex, string $reason, User $uploader): ContentQuestionDeleteRequest
    {
        $task = $this->access->assignedTask($uploader, $chapter);
        if (! $task) {
            throw new InvalidArgumentException('You are not assigned to this chapter.');
        }

        if (! $task->isLockedForUploaderDelete()) {
            throw new InvalidArgumentException('You can delete this question yourself before it is published.');
        }

        $items = $chapter->extraction_items ?? [];
        if (! isset($items[$itemIndex])) {
            throw new InvalidArgumentException('Question not found.');
        }

        $existing = ContentQuestionDeleteRequest::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('item_index', $itemIndex)
            ->where('status', ContentQuestionDeleteRequest::STATUS_PENDING)
            ->first();

        if ($existing) {
            $existing->update(['reason' => $reason]);

            return $existing->fresh();
        }

        $question = $this->publishService->publishedQuestionForItemIndex($chapter, $itemIndex);

        return ContentQuestionDeleteRequest::query()->create([
            'content_upload_task_id' => $task->id,
            'textbook_chapter_id' => $chapter->id,
            'item_index' => $itemIndex,
            'question_id' => $question?->id,
            'question_text' => trim((string) ($items[$itemIndex]['question_text'] ?? '')),
            'requested_by' => $uploader->id,
            'reason' => $reason,
            'status' => ContentQuestionDeleteRequest::STATUS_PENDING,
        ]);
    }

    public function approveDeleteRequest(ContentQuestionDeleteRequest $request, User $admin, ?string $note = null): void
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException('This delete request was already reviewed.');
        }

        $chapter = $request->chapter;
        $this->deleteItem($chapter, $request->item_index, $admin, asAdmin: true);

        $request->update([
            'status' => ContentQuestionDeleteRequest::STATUS_APPROVED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);
    }

    public function rejectDeleteRequest(ContentQuestionDeleteRequest $request, User $admin, ?string $note = null): void
    {
        if (! $request->isPending()) {
            throw new InvalidArgumentException('This delete request was already reviewed.');
        }

        $request->update([
            'status' => ContentQuestionDeleteRequest::STATUS_REJECTED,
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'admin_note' => $note,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     * @return list<array<string, mixed>>
     */
    private function shrinkSetPlanAfterDelete(array $plan, int $deletedIndex, int $remainingCount): array
    {
        if ($plan === []) {
            return $plan;
        }

        $deletedPosition = $deletedIndex + 1;
        $adjusted = [];

        foreach ($plan as $row) {
            $from = (int) ($row['q_from'] ?? 1);
            $to = (int) ($row['q_to'] ?? $from);

            if ($deletedPosition < $from) {
                $row['q_from'] = $from - 1;
                $row['q_to'] = $to - 1;
            } elseif ($deletedPosition <= $to) {
                $row['q_to'] = $to - 1;
            }

            if ((int) $row['q_to'] >= (int) $row['q_from'] && (int) $row['q_from'] >= 1) {
                $adjusted[] = $row;
            }
        }

        if ($adjusted === []) {
            return $plan;
        }

        $last = count($adjusted) - 1;
        $adjusted[$last]['q_to'] = $remainingCount;

        return $adjusted;
    }

    /**
     * @param  list<array<string, mixed>>  $newItems
     */
    private function publishNewItemsIfLive(TextbookChapter $chapter, array $newItems, User $user): void
    {
        if ($chapter->mcqWorksheetIds() === [] || $newItems === []) {
            return;
        }

        $this->publishService->appendPublishedMcqs($chapter, $newItems, $user);
    }
}
