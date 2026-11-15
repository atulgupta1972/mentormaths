<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppInboxMessage;
use App\Services\WhatsAppInboxService;
use App\Support\WhatsApp\WhatsAppPhone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WhatsAppInboxController extends Controller
{
    public function index(Request $request, WhatsAppInboxService $inbox): Response
    {
        $latestIds = WhatsAppInboxMessage::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('mobile')
            ->pluck('id');

        $latest = WhatsAppInboxMessage::query()
            ->with('student:id,name')
            ->whereIn('id', $latestIds)
            ->orderByDesc('id')
            ->get();

        $unread = WhatsAppInboxMessage::query()
            ->where('direction', WhatsAppInboxMessage::DIRECTION_IN)
            ->whereNull('read_at')
            ->selectRaw('mobile, COUNT(*) as unread')
            ->groupBy('mobile')
            ->pluck('unread', 'mobile');

        $selected = WhatsAppPhone::normalize((string) $request->query('mobile', ''));
        if (! $selected || ! $latest->contains('mobile', $selected)) {
            $selected = $latest->first()?->mobile;
        }

        $messages = [];
        $sessionOpen = false;

        if ($selected) {
            WhatsAppInboxMessage::query()
                ->where('mobile', $selected)
                ->where('direction', WhatsAppInboxMessage::DIRECTION_IN)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            $messages = WhatsAppInboxMessage::query()
                ->where('mobile', $selected)
                ->orderBy('id')
                ->get()
                ->map(fn (WhatsAppInboxMessage $message) => [
                    'id' => $message->id,
                    'direction' => $message->direction,
                    'body' => $message->body,
                    'at' => ($message->received_at ?? $message->created_at)?->timezone((string) config('app.timezone'))->format('d M Y H:i'),
                    'status' => $message->status,
                    'error' => $message->error,
                ])
                ->all();

            $sessionOpen = $inbox->sessionOpen($selected);
        }

        return Inertia::render('Admin/WhatsApp/Inbox', [
            'threads' => $latest->map(fn (WhatsAppInboxMessage $message) => [
                'mobile' => $message->mobile,
                'title' => $message->recipient_label ?: $message->contact_name ?: $this->displayMobile($message->mobile),
                'student_name' => $message->student?->name,
                'preview' => $message->body,
                'at' => ($message->received_at ?? $message->created_at)?->timezone((string) config('app.timezone'))->format('d M H:i'),
                'unread' => $message->mobile === $selected ? 0 : (int) ($unread[$message->mobile] ?? 0),
            ])->values()->all(),
            'selected' => $selected,
            'messages' => $messages,
            'session_open' => $sessionOpen,
            'webhook_url' => url('/webhooks/whatsapp'),
            'webhook_ready' => filled(config('whatsapp.webhook.verify_token')),
        ]);
    }

    public function reply(Request $request, string $mobile, WhatsAppInboxService $inbox): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $result = $inbox->reply($mobile, trim($data['body']));

        if (! $result['sent']) {
            return back()->withErrors(['body' => $result['error']]);
        }

        return back()->with('success', 'Reply sent.');
    }

    private function displayMobile(string $mobile): string
    {
        if (str_starts_with($mobile, '91') && strlen($mobile) === 12) {
            return '+91 '.substr($mobile, 2, 5).' '.substr($mobile, 7);
        }

        return '+'.$mobile;
    }
}
