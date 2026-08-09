<?php

namespace App\Support\WhatsApp\Contracts;

interface WhatsAppDriver
{
    /**
     * @return array{sent: bool, message_id: ?string, error: ?string}
     */
    public function sendText(string $to, string $message): array;

    public function isConfigured(): bool;
}
