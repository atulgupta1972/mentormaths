<?php

namespace App\Support\WhatsApp\Drivers;

use App\Support\WhatsApp\Contracts\WhatsAppDriver;

class ManualDriver implements WhatsAppDriver
{
    public function sendText(string $to, string $message): array
    {
        return [
            'sent' => false,
            'message_id' => null,
            'error' => 'manual',
        ];
    }

    public function sendTemplate(string $to, array $template): array
    {
        return [
            'sent' => false,
            'message_id' => null,
            'error' => 'manual',
        ];
    }

    public function isConfigured(): bool
    {
        return false;
    }
}
