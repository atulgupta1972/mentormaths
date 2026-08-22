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

        $schedule = config('whatsapp.schedule', []);
        $day = (int) ($schedule['weekly_summary_day'] ?? 6);

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
            'channels' => self::channelRows(),
            'schedule' => [
                'weekly_summary_enabled' => (bool) ($schedule['weekly_summary_enabled'] ?? true),
                'weekly_summary_day' => $day,
                'weekly_summary_day_label' => self::dayLabel($day),
                'weekly_summary_time' => (string) ($schedule['weekly_summary_time'] ?? '08:00'),
                'daily_balance_enabled' => (bool) ($schedule['daily_balance_enabled'] ?? true),
                'daily_balance_time' => (string) ($schedule['daily_balance_time'] ?? '14:00'),
            ],
            'templates' => [
                'enabled' => (bool) config('whatsapp.templates.enabled', true),
                'default_name' => (string) config('whatsapp.templates.default_name', 'mentor_maths_update'),
                'language' => (string) config('whatsapp.templates.language', 'en'),
                'body' => self::templateBodyExample(),
            ],
            'setup' => self::setupSteps(),
        ];
    }

    /**
     * @return list<array{key: string, label: string, enabled: bool, trigger: string}>
     */
    private static function channelRows(): array
    {
        return [
            [
                'key' => 'progress_summary',
                'label' => 'Weekly progress summary',
                'enabled' => WhatsAppSender::channelEnabled('progress_summary'),
                'trigger' => 'Scheduled (see below) or manual from student profile',
            ],
            [
                'key' => 'daily_balance',
                'label' => 'Daily balance reminder',
                'enabled' => WhatsAppSender::channelEnabled('daily_balance'),
                'trigger' => 'Scheduled daily (see below)',
            ],
            [
                'key' => 'assignment_assigned',
                'label' => 'New assignment',
                'enabled' => WhatsAppSender::channelEnabled('assignment_assigned'),
                'trigger' => 'Immediately when you assign work',
            ],
            [
                'key' => 'pending_work',
                'label' => 'Pending work reminder',
                'enabled' => WhatsAppSender::channelEnabled('pending_work'),
                'trigger' => 'When you send pending-work emails (bulk or per student)',
            ],
        ];
    }

    private static function dayLabel(int $day): string
    {
        return match ($day) {
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
            default => 'Saturday',
        };
    }

    private static function templateBodyExample(): string
    {
        $name = (string) config('whatsapp.templates.default_name', 'mentor_maths_update');

        return <<<TXT
Create one Utility template in WhatsApp Manager → Message templates:

Name: {$name}
Language: English (match WHATSAPP_TEMPLATE_LANGUAGE, default: en)
Category: Utility
Body:
Hello from Mentor Maths.

{{1}}

View details: {{2}}
TXT;
    }

    /**
     * @return list<string>
     */
    private static function setupSteps(): array
    {
        return [
            'Create the approved template above in Meta WhatsApp Manager (status must be Approved).',
            'Set WHATSAPP_TEMPLATES_ENABLED=true (default) so parents receive messages without messaging you first.',
            'Mark parent mobiles as Notify on each student profile.',
            'Use Admin → Email & notifications to review sent messages and schedule settings.',
            'Test: php artisan whatsapp:test 9876543210',
        ];
    }
}
