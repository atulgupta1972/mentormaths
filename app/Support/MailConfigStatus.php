<?php

namespace App\Support;

class MailConfigStatus
{
    /**
     * @return array<string, mixed>
     */
    public static function forAdmin(): array
    {
        $mailer = (string) config('mail.default', 'log');
        $isLogMailer = $mailer === 'log';

        return [
            'mailer' => $mailer,
            'is_log_mailer' => $isLogMailer,
            'is_smtp' => $mailer === 'smtp',
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'smtp_host' => config('mail.mailers.smtp.host'),
            'smtp_port' => config('mail.mailers.smtp.port'),
            'admin_notify_email' => RegistrationMailer::resolveAdminNotifyEmail(),
            'daily_balance_enabled' => (bool) config('progress_summary.daily_balance_enabled', true),
            'daily_balance_time' => (string) config('progress_summary.daily_balance_time', '14:00'),
            'cron_command' => '* * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
            'env_hints' => [
                'MAIL_MAILER=smtp',
                'MAIL_HOST=smtp.gmail.com',
                'MAIL_PORT=587',
                'MAIL_USERNAME=your@gmail.com',
                'MAIL_PASSWORD=app-password',
                'DAILY_BALANCE_EMAIL_ENABLED=true',
                'DAILY_BALANCE_EMAIL_TIME=14:00',
                'REGISTRATION_NOTIFY_EMAIL=admin@mentormaths.in',
            ],
        ];
    }
}
