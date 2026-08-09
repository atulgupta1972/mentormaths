<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContentUploadTask;
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
        $unpaid = $this->finance->unpaidPayableTasks();

        $payments = ContentUploaderPayment::query()
            ->with([
                'task.assignee:id,name,email',
                'task.textbookChapter.textbook.gradeLevel',
                'paidBy:id,name',
            ])
            ->orderByDesc('paid_on')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return Inertia::render('Admin/Finance/Index', [
            'unpaid' => $unpaid->map(fn (ContentUploadTask $task) => $this->finance->serializeUnpaidTask($task))->values()->all(),
            'unpaid_total_inr' => (int) $unpaid->sum(fn (ContentUploadTask $task) => $task->payableAmountInr()),
            'payments' => $payments->map(fn (ContentUploaderPayment $payment) => $this->finance->serializePayment($payment))->values()->all(),
            'paid_total_inr' => (int) $payments->sum('amount_inr'),
        ]);
    }

    public function storePayment(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'content_upload_task_id' => ['required', 'integer', 'exists:content_upload_tasks,id'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:upi,bank,other'],
            'upi_or_reference' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $task = ContentUploadTask::query()
            ->with(['assignee', 'payment', 'textbookChapter.textbook.gradeLevel'])
            ->findOrFail((int) $validated['content_upload_task_id']);

        try {
            $payment = $this->finance->recordPayment($task, $request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $name = $payment->task?->assignee?->name ?? 'uploader';
        $amount = number_format((int) $payment->amount_inr);
        $emailed = $payment->emailed_at ? ' Email sent.' : ' Email could not be sent (check uploader email / mail settings).';

        return back()->with('success', "Payment of ₹{$amount} recorded for {$name}.{$emailed}");
    }
}
