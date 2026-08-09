<?php

namespace App\Support\WhatsApp\Drivers;

use App\Support\WhatsApp\Contracts\WhatsAppDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaCloudDriver implements WhatsAppDriver
{
    public function sendText(string $to, string $message): array
    {
        $phoneNumberId = config('whatsapp.meta.phone_number_id');
        $accessToken = config('whatsapp.meta.access_token');
        $apiVersion = config('whatsapp.meta.api_version', 'v21.0');

        if (! $phoneNumberId || ! $accessToken) {
            return [
                'sent' => false,
                'message_id' => null,
                'error' => 'not_configured',
            ];
        }

        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => true,
                        'body' => $message,
                    ],
                ]);

            if ($response->successful()) {
                $messageId = $response->json('messages.0.id');

                return [
                    'sent' => true,
                    'message_id' => is_string($messageId) ? $messageId : null,
                    'error' => null,
                ];
            }

            $error = $response->json('error.message') ?? $response->body();

            Log::warning('WhatsApp Meta API send failed.', [
                'to' => $to,
                'status' => $response->status(),
                'error' => $error,
            ]);

            return [
                'sent' => false,
                'message_id' => null,
                'error' => is_string($error) ? $error : 'send_failed',
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsApp Meta API exception.', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'sent' => false,
                'message_id' => null,
                'error' => 'send_failed',
            ];
        }
    }

    public function isConfigured(): bool
    {
        return filled(config('whatsapp.meta.phone_number_id'))
            && filled(config('whatsapp.meta.access_token'));
    }
}
