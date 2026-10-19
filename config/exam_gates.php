<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First-term (half yearly) marks gate
    |--------------------------------------------------------------------------
    |
    | When true, students cannot start drills or practice/tests until they have
    | entered school marks for Term 1 / Half yearly.
    |
    */
    'first_term_marks_required' => (bool) env('FIRST_TERM_MARKS_REQUIRED', true),

    'first_term_exam_type' => env('FIRST_TERM_EXAM_TYPE', 'half_yearly'),

    /*
    | After Term 1 marks, every syllabus chapter must be tagged Term 1 or Term 2
    | (and both terms used) before drills / practice sums unlock.
    */
    'chapter_terms_required' => (bool) env('CHAPTER_TERMS_REQUIRED', true),

];
