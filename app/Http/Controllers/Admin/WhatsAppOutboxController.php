<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\WhatsAppMessage;
use App\Services\StudentMentorService;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppOutboxController extends Controller
{
    public function __construct(
        private StudentMentorService $mentorService,
    ) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:sent,failed,skipped'],
            'channel' => ['nullable', 'string', 'max:64'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $messages = [];
        $channels = [];

        if (Schema::hasTable('whatsapp_messages')) {
            $query = WhatsAppMessage::query()
                ->with('student:id,name')
                ->latest();

            if (filled($validated['status'] ?? null)) {
                $query->where('status', $validated['status']);
            }

            if (filled($validated['channel'] ?? null)) {
                $query->where('channel', $validated['channel']);
            }

            if (filled($validated['q'] ?? null)) {
                $needle = trim((string) $validated['q']);
                $query->where(function ($builder) use ($needle) {
                    $builder
                        ->where('to_mobile', 'like', '%'.$needle.'%')
                        ->orWhere('recipient_label', 'like', '%'.$needle.'%')
                        ->orWhere('message_body', 'like', '%'.$needle.'%')
                        ->orWhereHas('student', fn ($student) => $student->where('name', 'like', '%'.$needle.'%'));
                });
            }

            $messages = $query
                ->paginate(40)
                ->withQueryString()
                ->through(function (WhatsAppMessage $message) {
                    $resendMobile = $this->suggestedResendMobile($message);

                    return [
                        'id' => $message->id,
                        'channel' => $message->channel,
                        'channel_label' => $message->channelLabel(),
                        'to_mobile' => $message->to_mobile,
                        'recipient_label' => $message->recipient_label,
                        'student_id' => $message->student_id,
                        'student_name' => $message->student?->name,
                        'message_body' => $message->message_body,
                        'template_name' => $message->template_name,
                        'meta_message_id' => $message->meta_message_id,
                        'status' => $message->status,
                        'error' => $message->error,
                        'driver' => $message->driver,
                        'sent_at' => $message->created_at?->timezone('Asia/Kolkata')->format('d M Y, h:i A'),
                        'can_resend' => in_array($message->status, [WhatsAppMessage::STATUS_FAILED, WhatsAppMessage::STATUS_SKIPPED], true)
                            && filled($message->message_body),
                        'resend_mobile' => $resendMobile,
                        'resend_uses_current_mentor' => $resendMobile !== null
                            && $resendMobile !== $message->to_mobile
                            && $message->channel === 'assignment_completed'
                            && $message->student_id,
                    ];
                });

            $channels = WhatsAppMessage::query()
                ->select('channel')
                ->distinct()
                ->orderBy('channel')
                ->pluck('channel')
                ->map(fn (string $channel) => [
                    'value' => $channel,
                    'label' => (new WhatsAppMessage(['channel' => $channel]))->channelLabel(),
                ])
                ->values()
                ->all();
        }

        return Inertia::render('Admin/WhatsApp/Outbox', [
            'messages' => $messages,
            'channels' => $channels,
            'filters' => [
                'status' => $validated['status'] ?? '',
                'channel' => $validated['channel'] ?? '',
                'q' => $validated['q'] ?? '',
            ],
            'can_auto_send' => WhatsAppSender::canAutoSend(),
        ]);
    }

    public function resend(Request $request, WhatsAppMessage $message): RedirectResponse
    {
        if (! filled($message->message_body)) {
            return back()->with('warning', 'This outbox row has no message body to resend.');
        }

        $validated = $request->validate([
            'mobile' => ['nullable', 'string', 'max:20'],
            'use_current_mentor' => ['sometimes', 'boolean'],
        ]);

        $mobile = filled($validated['mobile'] ?? null)
            ? $validated['mobile']
            : null;

        if (! $mobile && $request->boolean('use_current_mentor', true)) {
            $mobile = $this->suggestedResendMobile($message);
        }

        $mobile = $mobile ?: $message->to_mobile;

        if (! filled($mobile)) {
            return back()->with('warning', 'No WhatsApp mobile available to resend.');
        }

        if (! WhatsAppSender::canAutoSend()) {
            return back()->with('warning', 'WhatsApp auto-send is not ready on the server.');
        }

        $label = $message->recipient_label;
        if (
            $message->channel === 'assignment_completed'
            && $message->student_id
            && $request->boolean('use_current_mentor', true)
        ) {
            $student = Student::query()->find($message->student_id);
            if ($student) {
                $mentor = $this->mentorService->resolve($student);
                if (filled($mentor['name'] ?? null)) {
                    $label = 'Mentor ('.$mentor['name'].')';
                }
            }
        }

        $result = WhatsAppSender::sendText(
            (string) $message->channel,
            $mobile,
            (string) $message->message_body,
            [
                'recipient_label' => $label,
                'student_id' => $message->student_id,
                'dashboard_url' => config('app.url'),
            ],
        );

        if ($result['sent'] ?? false) {
            return back()->with('success', 'WhatsApp resent to '.$mobile.'.');
        }

        $error = is_string($result['error'] ?? null) && $result['error'] !== ''
            ? ' '.$result['error']
            : '';

        return back()->with('warning', 'WhatsApp resend failed.'.$error);
    }

    private function suggestedResendMobile(WhatsAppMessage $message): ?string
    {
        if ($message->channel === 'assignment_completed' && $message->student_id) {
            $student = $message->relationLoaded('student')
                ? $message->student
                : Student::query()->find($message->student_id);

            if ($student) {
                $mentorMobile = $this->mentorService->resolve($student)['mobile'] ?? null;
                if (filled($mentorMobile)) {
                    return (string) $mentorMobile;
                }
            }
        }

        return filled($message->to_mobile) ? (string) $message->to_mobile : null;
    }
}
