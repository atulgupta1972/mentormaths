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

    'schedule' => [
        'weekly_summary_enabled' => env('WHATSAPP_WEEKLY_SUMMARY_ENABLED', true),
        'weekly_summary_day' => (int) env('WHATSAPP_WEEKLY_SUMMARY_DAY', 6),
        'weekly_summary_time' => env('WHATSAPP_WEEKLY_SUMMARY_TIME', '08:00'),
        'daily_balance_enabled' => env('WHATSAPP_DAILY_BALANCE_ENABLED', true),
        'daily_balance_time' => env('WHATSAPP_DAILY_BALANCE_TIME', env('DAILY_BALANCE_EMAIL_TIME', '14:00')),
    ],

    'templates' => [
        'enabled' => env('WHATSAPP_TEMPLATES_ENABLED', true),
        'default_name' => env('WHATSAPP_TEMPLATE_NAME', 'mentor_maths_update'),
        'language' => env('WHATSAPP_TEMPLATE_LANGUAGE', 'en'),
        'body_max_length' => 900,
        'names' => [
            'progress_summary' => env('WHATSAPP_TEMPLATE_PROGRESS_SUMMARY', env('WHATSAPP_TEMPLATE_NAME', 'mentor_maths_update')),
            'daily_balance' => env('WHATSAPP_TEMPLATE_DAILY_BALANCE', env('WHATSAPP_TEMPLATE_NAME', 'mentor_maths_update')),
            'assignment_assigned' => env('WHATSAPP_TEMPLATE_ASSIGNMENT', env('WHATSAPP_TEMPLATE_NAME', 'mentor_maths_update')),
            'pending_work' => env('WHATSAPP_TEMPLATE_PENDING_WORK', env('WHATSAPP_TEMPLATE_NAME', 'mentor_maths_update')),
        ],
    ],

    'meta' => [
        'api_version' => env('WHATSAPP_META_API_VERSION', 'v21.0'),
        'phone_number_id' => env('WHATSAPP_META_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_META_ACCESS_TOKEN'),
        'business_account_id' => env('WHATSAPP_META_BUSINESS_ACCOUNT_ID'),
    ],

    'log_messages' => env('WHATSAPP_LOG_MESSAGES', true),

    'log_to_database' => env('WHATSAPP_LOG_TO_DATABASE', true),

    'max_message_length' => 4000,
];
