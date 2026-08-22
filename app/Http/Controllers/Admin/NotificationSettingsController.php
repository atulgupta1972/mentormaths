<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\WhatsAppMessage;
use App\Services\AdminGradeContext;
use App\Services\PendingWorkEmailService;
use App\Support\MailConfigStatus;
use App\Support\WhatsAppConfigStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationSettingsController extends Controller
{
    public function __construct(
        private PendingWorkEmailService $pendingWorkEmailService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();
        $grade = $this->gradeContext->resolve($request);

        return Inertia::render('Admin/Notifications/Index', [
            'mailSettings' => MailConfigStatus::forAdmin(),
            'whatsappSettings' => WhatsAppConfigStatus::forAdmin(),
            'recentWhatsAppMessages' => WhatsAppMessage::query()
                ->with('student:id,name')
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (WhatsAppMessage $message) => [
                    'id' => $message->id,
                    'channel' => $message->channel,
                    'channel_label' => $message->channelLabel(),
                    'to_mobile' => $message->to_mobile,
                    'recipient_label' => $message->recipient_label,
                    'student_name' => $message->student?->name,
                    'message_preview' => str($message->message_body)->limit(120)->toString(),
                    'template_name' => $message->template_name,
                    'meta_message_id' => $message->meta_message_id,
                    'status' => $message->status,
                    'error' => $message->error,
                    'driver' => $message->driver,
                    'sent_at' => $message->created_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A'),
                ]),
            'activeYear' => $activeYear?->only(['id', 'name']),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'gradeLevels' => GradeLevel::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['id', 'name']),
        ]);
    }

    public function sendPendingWorkAll(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'grade_level_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
        ]);

        $counts = $this->pendingWorkEmailService->sendToAll(
            $validated['grade_level_id'] ?? null,
            skipIfEmpty: true,
        );

        if ($counts['sent'] === 0 && $counts['failed'] === 0) {
            if ($counts['no_work'] > 0 && $counts['skipped'] === 0) {
                return back()->with('warning', 'No students had pending worksheets to email.');
            }

            if ($counts['skipped'] > 0 && $counts['no_work'] === 0) {
                return back()->with('warning', 'No emails sent — students are missing deliverable email addresses.');
            }

            return back()->with('warning', 'No pending-work emails were sent.');
        }

        $parts = [];

        if ($counts['sent'] > 0) {
            $parts[] = "{$counts['sent']} sent";
        }

        if ($counts['no_work'] > 0) {
            $parts[] = "{$counts['no_work']} skipped (nothing pending)";
        }

        if ($counts['skipped'] > 0) {
            $parts[] = "{$counts['skipped']} skipped (no email on file)";
        }

        if ($counts['failed'] > 0) {
            $parts[] = "{$counts['failed']} failed";
        }

        $message = 'Pending worksheet emails: '.implode('; ', $parts).'. Parents CC\'d when email is on file.';

        if ($counts['failed'] > 0) {
            return back()->with('warning', $message);
        }

        return back()->with('success', $message);
    }
}
