<?php

return [
    /*
    | Drivers: manual (copy/open wa.me only), log (write to laravel.log), meta (Cloud API).
    | Legacy env PROGRESS_SUMMARY_WHATSAPP_DRIVER is still read as fallback.
    */
    'driver' => env('WHATSAPP_DRIVER', env('PROGRESS_SUMMARY_WHATSAPP_DRIVER', 'manual')),

    'enabled' => env('WHATSAPP_ENABLED', false),

    'default_country_code' => env('WHATSAPP_DEFAULT_COUNTRY_CODE', '91'),

    'channels' => [
        'progress_summary' => env('WHATSAPP_PROGRESS_SUMMARY', true),
        'daily_balance' => env('WHATSAPP_DAILY_BALANCE', true),
        'assignment_assigned' => env('WHATSAPP_ASSIGNMENT_ASSIGNED', true),
        'pending_work' => env('WHATSAPP_PENDING_WORK', true),
    ],

    'meta' => [
        'api_version' => env('WHATSAPP_META_API_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_META_ACCESS_TOKEN'),
        'business_account_id' => env('WHATSAPP_META_BUSINESS_ACCOUNT_ID'),
    ],

    'log_messages' => env('WHATSAPP_LOG_MESSAGES', true),

    'max_message_length' => 4000,
];
