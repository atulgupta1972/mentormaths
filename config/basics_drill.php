<?php

return [
    'timezone' => env('BASICS_DRILL_TIMEZONE', config('formula_drill.timezone')),

    'defaults' => [
        'tables_enabled' => true,
        'squares_enabled' => true,
        'cubes_enabled' => true,
        'table_from' => 2,
        'table_to' => 19,
        'multiplier_from' => 2,
        'multiplier_to' => 9,
        'square_from' => 2,
        'square_to' => 30,
        'cube_from' => 2,
        'cube_to' => 13,
        'squares_per_day' => 5,
        'cubes_per_day' => 3,
        'seconds_per_blank' => 5,
    ],
];
