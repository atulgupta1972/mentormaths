<?php

namespace App\Support;

use App\Support\WhatsApp\WhatsAppSender;

class WhatsAppConfigStatus
{
    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(): array
    {
        $driver = (string) config('whatsapp.driver', 'manual');
        $enabled = (bool) config('whatsapp.enabled', false);
        $metaConfigured = filled(config('whatsapp.meta.phone_number_id'))
            && filled(config('whatsapp.meta.access_token'));

        $canAutoSend = $enabled && $driver !== 'manual' && (
            $driver === 'log' || ($driver === 'meta' && $metaConfigured)
        );

        return [
            'enabled' => $enabled,
            'driver' => $driver,
            'can_auto_send' => $canAutoSend,
            'meta' => [
                'configured' => $metaConfigured,
                'phone_number_id_set' => filled(config('whatsapp.meta.phone_number_id')),
                'access_token_set' => filled(config('whatsapp.meta.access_token')),
                'api_version' => config('whatsapp.meta.api_version', 'v21.0'),
            ],
            'channels' => config('whatsapp.channels', []),
            'setup' => [
                'Create a Meta Business app with WhatsApp Cloud API.',
                'Add WHATSAPP_META_PHONE_NUMBER_ID and WHATSAPP_META_ACCESS_TOKEN to .env.',
                'Set WHATSAPP_DRIVER=meta and WHATSAPP_ENABLED=true.',
                'For reminders outside 24h, use approved message templates (text works for opted-in users within service window).',
                'Use php artisan whatsapp:test 9876543210 to verify after deploy.',
            ],
        ];
    }
}
