<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentUploaderPayment;
use App\Services\ContentFinanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentFinanceController extends Controller
{
    public function __construct(
        private ContentFinanceService $finance,
    ) {}

    public function index(): Response
    {
        $unpaidGroups = $this->finance->unpaidGroupedByAssignee();
        $unpaidTasks = $this->finance->unpaidPayableTasks();

        $payments = ContentUploaderPayment::query()
            ->with([
                'task.assignee:id,name,email',
                'task.textbookChapter.textbook.gradeLevel',
                'paidBy:id,name',
            ])
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return Inertia::render('Admin/Finance/Index', [
            'unpaid_groups' => $unpaidGroups,
            'unpaid_total_inr' => (int) $unpaidTasks->sum(fn ($task) => $task->payableAmountInr()),
            'unpaid_chapter_count' => $unpaidTasks->count(),
            'payment_groups' => $this->finance->serializePaymentsGrouped($payments),
            'paid_total_inr' => (int) $payments->sum('amount_inr'),
        ]);
    }

    public function storePayment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'content_upload_task_ids' => ['required', 'array', 'min:1'],
            'content_upload_task_ids.*' => ['integer', 'exists:content_upload_tasks,id'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:upi,bank,other'],
            'upi_or_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $taskIds = array_map('intval', $validated['content_upload_task_ids']);

        try {
            $payments = $this->finance->recordBatchPayment($taskIds, $request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $name = $payments->first()?->task?->assignee?->name ?? 'uploader';
        $amount = number_format((int) $payments->sum('amount_inr'));
        $count = $payments->count();
        $emailed = $payments->every(fn ($p) => $p->emailed_at)
            ? ' Email sent.'
            : ' Email could not be sent (check uploader email / mail settings).';

        return back()->with(
            'success',
            "Payment of ₹{$amount} for {$count} chapter".($count === 1 ? '' : 's')." recorded for {$name}.{$emailed}",
        );
    }
}
