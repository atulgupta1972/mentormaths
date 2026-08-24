<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Trial access code (tcode)
    |--------------------------------------------------------------------------
    |
    | Self-serve signup issues a tcode used for login. Validity starts at
    | generation and lasts trial_days. Admin can extend from the code master.
    |
    */

    'trial_days' => (int) env('ACCESS_TRIAL_DAYS', 15),

    'code_prefix' => env('ACCESS_CODE_PREFIX', 'MM'),

    'code_length' => 6,

];
