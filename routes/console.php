<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('students:send-weekly-summaries')
    ->weeklyOn(6, '08:00')
    ->timezone('Asia/Kolkata');

Schedule::command('students:send-daily-balance-reminders')
    ->dailyAt(config('progress_summary.daily_balance_time', '14:00'))
    ->timezone('Asia/Kolkata')
    ->when(fn () => config('progress_summary.daily_balance_enabled', true));

Schedule::command('whatsapp:send-weekly-summaries')
    ->weeklyOn(
        (int) config('whatsapp.schedule.weekly_summary_day', 6),
        (string) config('whatsapp.schedule.weekly_summary_time', '08:00'),
    )
    ->timezone('Asia/Kolkata')
    ->when(fn () => config('whatsapp.enabled', false)
        && config('whatsapp.schedule.weekly_summary_enabled', true));

Schedule::command('whatsapp:send-daily-balance-reminders')
    ->dailyAt((string) config('whatsapp.schedule.daily_balance_time', '14:00'))
    ->timezone('Asia/Kolkata')
    ->when(fn () => config('whatsapp.enabled', false)
        && config('whatsapp.schedule.daily_balance_enabled', true));

Schedule::command('written-submissions:grade-pending')
    ->everyMinute()
    ->withoutOverlapping();
