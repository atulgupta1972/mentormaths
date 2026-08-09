<?php

namespace App\Support\WhatsApp;

use App\Support\WhatsApp\Contracts\WhatsAppDriver;
use App\Support\WhatsApp\Drivers\LogDriver;
use App\Support\WhatsApp\Drivers\ManualDriver;
use App\Support\WhatsApp\Drivers\MetaCloudDriver;
use Illuminate\Support\Facades\Log;

class WhatsAppSender
{
    public static function driver(): WhatsAppDriver
    {
        $name = (string) config('whatsapp.driver', 'manual');

        return match ($name) {
            'log' => new LogDriver,
            'meta' => new MetaCloudDriver,
            default => new ManualDriver,
        };
    }

    public static function canAutoSend(): bool
    {
        if (! config('whatsapp.enabled', false)) {
            return false;
        }

        $driver = (string) config('whatsapp.driver', 'manual');

        if ($driver === 'manual') {
            return false;
        }

        return self::driver()->isConfigured();
    }

    public static function channelEnabled(string $channel): bool
    {
        return (bool) config("whatsapp.channels.{$channel}", true);
    }

    /**
     * @return array{sent: bool, to: ?string, message_id: ?string, error: ?string}
     */
    public static function sendText(string $channel, ?string $mobile, string $message): array
    {
        if (! self::channelEnabled($channel)) {
            return ['sent' => false, 'to' => null, 'message_id' => null, 'error' => 'channel_disabled'];
        }

        $to = WhatsAppPhone::normalize($mobile);

        if (! $to || ! WhatsAppPhone::isValid($to)) {
            return ['sent' => false, 'to' => $to, 'message_id' => null, 'error' => 'invalid_mobile'];
        }

        $maxLen = (int) config('whatsapp.max_message_length', 4000);
        if (strlen($message) > $maxLen) {
            $message = substr($message, 0, $maxLen - 40)."\n\n… (message truncated)";
        }

        if (config('whatsapp.log_messages', true)) {
            Log::info('WhatsApp send attempt.', [
                'channel' => $channel,
                'to' => $to,
                'driver' => config('whatsapp.driver'),
            ]);
        }

        if (! self::canAutoSend()) {
            return ['sent' => false, 'to' => $to, 'message_id' => null, 'error' => 'manual'];
        }

        $result = self::driver()->sendText($to, $message);

        return [
            'sent' => $result['sent'],
            'to' => $to,
            'message_id' => $result['message_id'] ?? null,
            'error' => $result['error'] ?? null,
        ];
    }

    /**
     * @param  list<array{mobile: string, label?: string, message: string}>  $notifications
     * @return array{sent_count: int, failed_count: int, skipped_count: int, results: list<array<string, mixed>>}
     */
    public static function sendNotifications(string $channel, array $notifications): array
    {
        $sentCount = 0;
        $failedCount = 0;
        $skippedCount = 0;
        $results = [];

        foreach ($notifications as $notification) {
            $result = self::sendText(
                $channel,
                $notification['mobile'],
                $notification['message'],
            );

            $row = array_merge($notification, $result);
            $results[] = $row;

            if ($result['sent']) {
                $sentCount++;
            } elseif (in_array($result['error'], ['manual', 'channel_disabled'], true)) {
                $skippedCount++;
            } else {
                $failedCount++;
            }
        }

        return [
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'skipped_count' => $skippedCount,
            'results' => $results,
        ];
    }
}
