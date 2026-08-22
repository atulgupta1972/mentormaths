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
     * @param  array<string, mixed>  $context
     * @return array{sent: bool, to: ?string, message_id: ?string, error: ?string, used_template: bool}
     */
    public static function sendText(string $channel, ?string $mobile, string $message, array $context = []): array
    {
        if (! self::channelEnabled($channel)) {
            $result = ['sent' => false, 'to' => null, 'message_id' => null, 'error' => 'channel_disabled', 'used_template' => false];
            WhatsAppMessageLogger::log($channel, null, $message, $result, $context);

            return $result;
        }

        $to = WhatsAppPhone::normalize($mobile);

        if (! $to || ! WhatsAppPhone::isValid($to)) {
            $result = ['sent' => false, 'to' => $to, 'message_id' => null, 'error' => 'invalid_mobile', 'used_template' => false];
            WhatsAppMessageLogger::log($channel, $to, $message, $result, $context);

            return $result;
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
            $result = ['sent' => false, 'to' => $to, 'message_id' => null, 'error' => 'manual', 'used_template' => false];
            WhatsAppMessageLogger::log($channel, $to, $message, $result, $context);

            return $result;
        }

        $usedTemplate = false;
        $templateName = null;

        if (WhatsAppTemplateBuilder::shouldUseTemplate()) {
            $template = WhatsAppTemplateBuilder::forChannel(
                $channel,
                $message,
                is_string($context['dashboard_url'] ?? null) ? $context['dashboard_url'] : null,
            );
            $templateName = $template['name'];
            $sendResult = self::driver()->sendTemplate($to, $template);
            $usedTemplate = true;
        } else {
            $sendResult = self::driver()->sendText($to, $message);
        }

        $result = [
            'sent' => $sendResult['sent'],
            'to' => $to,
            'message_id' => $sendResult['message_id'] ?? null,
            'error' => $sendResult['error'] ?? null,
            'used_template' => $usedTemplate,
        ];

        WhatsAppMessageLogger::log($channel, $to, $message, $result, array_merge($context, [
            'template_name' => $templateName,
        ]));

        return $result;
    }

    /**
     * @param  list<array{mobile: string, label?: string, message: string, student_id?: int, dashboard_url?: string}>  $notifications
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
                [
                    'recipient_label' => $notification['label'] ?? null,
                    'student_id' => $notification['student_id'] ?? null,
                    'dashboard_url' => $notification['dashboard_url'] ?? null,
                ],
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
