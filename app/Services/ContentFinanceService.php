<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentUploaderPayment;
use App\Models\User;
use App\Support\ContentOperationsMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

class ContentFinanceService
{
    /**
     * Published / verified chapter work that still needs payment.
     *
     * @return Collection<int, ContentUploadTask>
     */
    public function unpaidPayableTasks(): Collection
    {
        return ContentUploadTask::query()
            ->whereIn('status', [
                ContentUploadTask::STATUS_VERIFIED,
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_PUBLISHED,
            ])
            ->where(function ($query) {
                $query->where('agreed_amount_inr', '>', 0)
                    ->orWhere(function ($inner) {
                        $inner->whereNull('agreed_amount_inr')
                            ->where('offered_amount_inr', '>', 0);
                    });
            })
            ->whereDoesntHave('payment')
            ->with([
                'assignee:id,name,email',
                'textbookChapter.textbook.gradeLevel',
            ])
            ->orderBy('published_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array{
     *     paid_on: string,
     *     method: string,
     *     upi_or_reference: string,
     *     notes?: ?string
     * }  $payload
     */
    public function recordPayment(ContentUploadTask $task, User $admin, array $payload): ContentUploaderPayment
    {
        if (! $task->isPayable()) {
            throw new \InvalidArgumentException('This chapter is not ready for payment yet (verify/publish first).');
        }

        if ($task->payment()->exists()) {
            throw new \InvalidArgumentException('Payment is already recorded for this chapter.');
        }

        $amount = $task->payableAmountInr();
        if ($amount <= 0) {
            throw new \InvalidArgumentException('No agreed amount on this task.');
        }

        $payment = DB::transaction(function () use ($task, $admin, $payload, $amount) {
            return ContentUploaderPayment::query()->create([
                'content_upload_task_id' => $task->id,
                'amount_inr' => $amount,
                'paid_on' => $payload['paid_on'],
                'method' => $payload['method'],
                'upi_or_reference' => trim($payload['upi_or_reference']),
                'notes' => filled(trim((string) ($payload['notes'] ?? '')))
                    ? trim((string) $payload['notes'])
                    : null,
                'paid_by_user_id' => $admin->id,
            ]);
        });

        $payment->load([
            'task.assignee',
            'task.textbookChapter.textbook.gradeLevel',
            'paidBy:id,name',
        ]);

        if (ContentOperationsMailer::notifyPaymentRecorded($payment)) {
            $payment->update(['emailed_at' => now()]);
        }

        return $payment->fresh([
            'task.assignee',
            'task.textbookChapter.textbook.gradeLevel',
            'paidBy:id,name',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeUnpaidTask(ContentUploadTask $task): array
    {
        $chapter = $task->textbookChapter;

        return [
            'id' => $task->id,
            'status' => $task->status,
            'status_label' => $task->statusLabel(),
            'amount_inr' => $task->payableAmountInr(),
            'published_at' => $task->published_at?->toDateString(),
            'assignee' => $task->assignee?->only(['id', 'name', 'email']),
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'textbook_name' => $chapter->textbook?->name,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializePayment(ContentUploaderPayment $payment): array
    {
        $task = $payment->task;
        $chapter = $task?->textbookChapter;

        return [
            'id' => $payment->id,
            'amount_inr' => $payment->amount_inr,
            'paid_on' => $payment->paid_on?->toDateString(),
            'method' => $payment->method,
            'method_label' => $payment->methodLabel(),
            'upi_or_reference' => $payment->upi_or_reference,
            'notes' => $payment->notes,
            'emailed_at' => $payment->emailed_at?->toDateTimeString(),
            'paid_by' => $payment->paidBy?->only(['id', 'name']),
            'assignee' => $task?->assignee?->only(['id', 'name', 'email']),
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'textbook_name' => $chapter->textbook?->name,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
            ] : null,
            'task_id' => $task?->id,
        ];
    }
}
