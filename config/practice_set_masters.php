<?php

use App\Support\PracticeSetTier;

/**
 * Master difficulty mixes and marks for Learner / Achiever / Expert sets.
 * Selecting a profile auto-fills easy/medium/hard counts when generating prompts.
 */
return [

    'marks' => [
        'easy' => 1,
        'medium' => 2,
        'hard' => 3,
    ],

    'profiles' => [
        PracticeSetTier::STARTER => [
            'key' => 'learner',
            'label' => 'Learner',
            'tagline' => 'Mostly easy — build confidence',
            'easy' => 15,
            'medium' => 5,
            'hard' => 0,
            'total' => 20,
            'color' => 'sky',
        ],
        PracticeSetTier::BUILDER => [
            'key' => 'achiever',
            'label' => 'Achiever',
            'tagline' => 'Mostly medium — stretch with a few hard',
            'easy' => 5,
            'medium' => 13,
            'hard' => 2,
            'total' => 20,
            'color' => 'amber',
        ],
        PracticeSetTier::CHAMPION => [
            'key' => 'expert',
            'label' => 'Expert',
            'tagline' => 'All hard — exam-ready challenge',
            'easy' => 0,
            'medium' => 0,
            'hard' => 15,
            'total' => 15,
            'color' => 'emerald',
        ],
    ],

];
