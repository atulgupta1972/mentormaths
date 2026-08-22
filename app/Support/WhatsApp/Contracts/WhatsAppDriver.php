<?php

namespace App\Support\WhatsApp\Contracts;

interface WhatsAppDriver
{
    /**
     * @return array{sent: bool, message_id: ?string, error: ?string}
     */
    public function sendText(string $to, string $message): array;

    /**
     * @param  array{name: string, language: string, components: list<array<string, mixed>>}  $template
     * @return array{sent: bool, message_id: ?string, error: ?string}
     */
    public function sendTemplate(string $to, array $template): array;

    public function isConfigured(): bool;
}
