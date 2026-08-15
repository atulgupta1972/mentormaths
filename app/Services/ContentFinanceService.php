<?php

namespace App\Services;

use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\ContentUploaderPayment;
use App\Models\User;
use App\Support\ContentOperationsMailer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
                'textbookChapter',
            ])
            ->orderBy('published_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return list<array{
     *     assignee: array{id: int, name: string, email: string}|null,
     *     tasks: list<array<string, mixed>>,
     *     task_ids: list<int>,
     *     total_inr: int,
     *     task_count: int
     * }>
     */
    public function unpaidGroupedByAssignee(): array
    {
        return $this->unpaidPayableTasks()
            ->groupBy(fn (ContentUploadTask $task) => (int) ($task->assigned_to_user_id ?? 0))
            ->map(function (Collection $tasks) {
                /** @var ContentUploadTask $first */
                $first = $tasks->first();

                return [
                    'assignee' => $first->assignee?->only(['id', 'name', 'email']),
                    'tasks' => $tasks
                        ->map(fn (ContentUploadTask $task) => $this->serializeUnpaidTask($task))
                        ->values()
                        ->all(),
                    'task_ids' => $tasks->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    'total_inr' => (int) $tasks->sum(fn (ContentUploadTask $task) => $task->payableAmountInr()),
                    'task_count' => $tasks->count(),
                ];
            })
            ->sortBy(fn (array $group) => mb_strtolower((string) ($group['assignee']['name'] ?? '')))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $taskIds
     * @param  array{
     *     paid_on: string,
     *     method: string,
     *     upi_or_reference: string,
     *     notes?: ?string
     * }  $payload
     * @return Collection<int, ContentUploaderPayment>
     */
    public function recordBatchPayment(array $taskIds, User $admin, array $payload): Collection
    {
        $taskIds = array_values(array_unique(array_map('intval', $taskIds)));

        if ($taskIds === []) {
            throw new \InvalidArgumentException('Select at least one chapter to pay.');
        }

        $tasks = ContentUploadTask::query()
            ->whereIn('id', $taskIds)
            ->with([
                'assignee:id,name,email',
                'payment',
                'textbookChapter.textbook.gradeLevel',
                'textbookChapter',
            ])
            ->get()
            ->keyBy('id');

        if ($tasks->count() !== count($taskIds)) {
            throw new \InvalidArgumentException('One or more chapters could not be found.');
        }

        $assigneeIds = $tasks->pluck('assigned_to_user_id')->unique()->filter()->values();
        if ($assigneeIds->count() !== 1) {
            throw new \InvalidArgumentException('All chapters in one payment must belong to the same uploader.');
        }

        foreach ($tasks as $task) {
            if (! $task->isPayable()) {
                throw new \InvalidArgumentException('One or more chapters are not ready for payment yet.');
            }

            if ($task->payment()->exists()) {
                throw new \InvalidArgumentException('Payment is already recorded for one of the selected chapters.');
            }

            if ($task->payableAmountInr() <= 0) {
                throw new \InvalidArgumentException('One or more chapters have no agreed amount.');
            }
        }

        $batchId = (string) Str::uuid();
        $reference = trim($payload['upi_or_reference']);
        $notes = filled(trim((string) ($payload['notes'] ?? '')))
            ? trim((string) $payload['notes'])
            : null;

        $payments = DB::transaction(function () use ($tasks, $taskIds, $admin, $payload, $batchId, $reference, $notes) {
            $created = collect();

            foreach ($taskIds as $taskId) {
                /** @var ContentUploadTask $task */
                $task = $tasks->get($taskId);

                $created->push(ContentUploaderPayment::query()->create([
                    'content_upload_task_id' => $task->id,
                    'batch_id' => $batchId,
                    'amount_inr' => $task->payableAmountInr(),
                    'paid_on' => $payload['paid_on'],
                    'method' => $payload['method'],
                    'upi_or_reference' => $reference,
                    'notes' => $notes,
                    'paid_by_user_id' => $admin->id,
                ]));
            }

            return $created;
        });

        $payments = ContentUploaderPayment::query()
            ->where('batch_id', $batchId)
            ->with([
                'task.assignee',
                'task.textbookChapter.textbook.gradeLevel',
                'paidBy:id,name',
            ])
            ->orderBy('id')
            ->get();

        if (ContentOperationsMailer::notifyBatchPaymentRecorded($payments)) {
            ContentUploaderPayment::query()
                ->where('batch_id', $batchId)
                ->update(['emailed_at' => now()]);

            $payments = ContentUploaderPayment::query()
                ->where('batch_id', $batchId)
                ->with([
                    'task.assignee',
                    'task.textbookChapter.textbook.gradeLevel',
                    'paidBy:id,name',
                ])
                ->orderBy('id')
                ->get();
        }

        return $payments;
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
        $payment = $this->recordBatchPayment([$task->id], $admin, $payload)->first();

        if (! $payment) {
            throw new \RuntimeException('Payment could not be recorded.');
        }

        return $payment;
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
            'rate_basis' => $task->rate_basis,
            'rate_basis_label' => $task->rateBasisLabel(),
            'rate_unit_inr' => $task->rateUnitInr(),
            'question_count' => $task->rate_basis === ContentRateCard::BASIS_PER_QUESTION
                ? $task->uploadedQuestionCount()
                : null,
            'rate_agreed_label' => $task->rateAgreedLabel(),
            'calculation_label' => $task->calculationLabel(),
            'rate_description' => $task->rateDescription(),
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
            'batch_id' => $payment->batch_id,
            'amount_inr' => $payment->amount_inr,
            'rate_agreed_label' => $task?->rateAgreedLabel(),
            'calculation_label' => $task?->calculationLabel(),
            'rate_description' => $task?->rateDescription(),
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

    /**
     * @param  Collection<int, ContentUploaderPayment>  $payments
     * @return list<array<string, mixed>>
     */
    public function serializePaymentsGrouped(Collection $payments): array
    {
        return $payments
            ->groupBy(function (ContentUploaderPayment $payment) {
                if ($payment->batch_id) {
                    return 'batch:'.$payment->batch_id;
                }

                return 'single:'.$payment->id;
            })
            ->map(function (Collection $group) {
                $first = $group->first();
                $assignee = $first?->task?->assignee;

                return [
                    'batch_id' => $first?->batch_id,
                    'paid_on' => $first?->paid_on?->toDateString(),
                    'method' => $first?->method,
                    'method_label' => $first?->methodLabel(),
                    'upi_or_reference' => $first?->upi_or_reference,
                    'notes' => $first?->notes,
                    'emailed_at' => $group->every(fn (ContentUploaderPayment $p) => $p->emailed_at !== null)
                        ? $group->max('emailed_at')?->toDateTimeString()
                        : null,
                    'paid_by' => $first?->paidBy?->only(['id', 'name']),
                    'assignee' => $assignee?->only(['id', 'name', 'email']),
                    'total_inr' => (int) $group->sum('amount_inr'),
                    'chapter_count' => $group->count(),
                    'payments' => $group
                        ->map(fn (ContentUploaderPayment $payment) => $this->serializePayment($payment))
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc(fn (array $group) => $group['paid_on'] ?? '')
            ->values()
            ->all();
    }
}
