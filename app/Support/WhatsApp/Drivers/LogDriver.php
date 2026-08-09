<?php

namespace App\Support\WhatsApp\Drivers;

use App\Support\WhatsApp\Contracts\WhatsAppDriver;
use Illuminate\Support\Facades\Log;

class LogDriver implements WhatsAppDriver
{
    public function sendText(string $to, string $message): array
    {
        Log::info('WhatsApp (log driver)', [
            'to' => $to,
            'message' => $message,
        ]);

        return [
            'sent' => true,
            'message_id' => 'log-'.now()->timestamp,
            'error' => null,
        ];
    }

    public function isConfigured(): bool
    {
        return true;
    }
}
