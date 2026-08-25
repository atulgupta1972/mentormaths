<?php

return [

    /*
    | Daily early-access digest for mentors / coaching classes:
    | enrolled student list, or enrolment nudge + how the system works.
    | Requires server cron: * * * * * php artisan schedule:run
    */
    'enabled' => env('MENTOR_EARLY_ACCESS_DIGEST_ENABLED', true),

    'time' => env('MENTOR_EARLY_ACCESS_DIGEST_TIME', '09:00'),

    /*
    | When true, only mentors with an active mentor tcode (trial / early access).
    | When false, every active user in the mentor group.
    */
    'active_tcode_only' => env('MENTOR_EARLY_ACCESS_DIGEST_TCODE_ONLY', true),

];
