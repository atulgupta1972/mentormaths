<?php

namespace App\Support\WhatsApp;

use App\Models\WhatsAppMessage;

class WhatsAppMessageLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public static function log(
        string $channel,
        ?string $to,
        string $message,
        array $result,
        array $context = [],
    ): void {
        if (! config('whatsapp.log_to_database', true)) {
            return;
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('whatsapp_messages')) {
            return;
        }

        $status = match (true) {
            $result['sent'] ?? false => WhatsAppMessage::STATUS_SENT,
            in_array($result['error'] ?? null, ['manual', 'channel_disabled'], true) => WhatsAppMessage::STATUS_SKIPPED,
            default => WhatsAppMessage::STATUS_FAILED,
        };

        WhatsAppMessage::query()->create([
            'channel' => $channel,
            'to_mobile' => $to ?? '',
            'recipient_label' => $context['recipient_label'] ?? null,
            'student_id' => $context['student_id'] ?? null,
            'message_body' => $message,
            'template_name' => $context['template_name'] ?? null,
            'meta_message_id' => $result['message_id'] ?? null,
            'status' => $status,
            'error' => $result['error'] ?? null,
            'driver' => (string) config('whatsapp.driver', 'manual'),
        ]);
    }
}
