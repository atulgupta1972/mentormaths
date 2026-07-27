<?php

return [
    'attach_pdf' => env('PROGRESS_SUMMARY_ATTACH_PDF', true),

    /*
    | Daily email listing balance work still to do (practice, test, written, corrections).
    | Requires server cron: * * * * * php artisan schedule:run
    */
    'daily_balance_enabled' => env('DAILY_BALANCE_EMAIL_ENABLED', true),
    'daily_balance_time' => env('DAILY_BALANCE_EMAIL_TIME', '14:00'),

    /*
    | WhatsApp auto-send requires a Business API provider (Interakt, Twilio, etc.).
    | Keep "manual" until credentials are configured — weekly job still emails + PDF.
    */
    'whatsapp_driver' => env('PROGRESS_SUMMARY_WHATSAPP_DRIVER', 'manual'),
];
