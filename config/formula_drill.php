<?php

return [
    'daily_question_count' => (int) env('FORMULA_DRILL_DAILY_COUNT', 10),
    'max_attempts_per_question' => (int) env('FORMULA_DRILL_MAX_ATTEMPTS', 4),
    'timezone' => env('FORMULA_DRILL_TIMEZONE', 'Asia/Kolkata'),
    'global_basics_scope' => 'global_basics',
];
