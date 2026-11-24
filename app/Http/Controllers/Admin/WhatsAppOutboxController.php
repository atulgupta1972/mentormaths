<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppOutboxController extends Controller
{
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
                ->through(fn (WhatsAppMessage $message) => [
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
                ]);

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
        ]);
    }
}
