<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'balance-equation-basic-6')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'balance-equation-basic-6',
            'title' => 'Balance the equation — Basic 6',
            'heading' => 'Balance the equation — Basic 6',
            'kind' => 'balance_equation',
            'status' => 'published',
            'accent' => 'rose',
            'method_blurb' => 'If a/b = c/d, then a × d = b × c. Cross the top of one fraction with the bottom of the other. If a side is a whole term plus a fraction, make one fraction first. If x is in the denominator, make those denominators the same, then cross.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 8,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exercises = [
            $this->pureCross(),
            $this->bracketCross(),
            $this->mixedCross(),
            $this->tenSums(),
            $this->variableDenominator(),
            $this->notebookSet(),
        ];

        foreach ($exercises as $exercise) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => $exercise['sort_order'],
                'difficulty' => $exercise['difficulty'],
                'is_demo' => $exercise['is_demo'],
                'question_text' => $exercise['question_text'],
                'answers' => json_encode($exercise['answers']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sourceIds = DB::table('mmm_cards')
            ->whereIn('slug', [
                'balance-the-equation',
                'balance-equation-basic-2',
                'balance-equation-basic-3',
                'balance-equation-basic-4',
                'balance-equation-basic-5',
            ])
            ->pluck('id');
        $chapterIds = DB::table('mmm_card_syllabus_chapter')
            ->whereIn('mmm_card_id', $sourceIds)
            ->pluck('syllabus_chapter_id')
            ->unique();
        foreach ($chapterIds as $chapterId) {
            DB::table('mmm_card_syllabus_chapter')->insert([
                'mmm_card_id' => $cardId,
                'syllabus_chapter_id' => $chapterId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function pureCross(): array
    {
        return [
            'sort_order' => 1,
            'difficulty' => 'easy',
            'is_demo' => true,
            'question_text' => 'Cross 3x/5 = 4/9. If a/b = c/d, then a × d = b × c.',
            'answers' => [
                'mode' => 'balance',
                'variable_tip' => 'If a/b = c/d, then a × d = b × c. The top of the left meets the bottom of the right. The bottom of the left meets the top of the right.',
                'play' => [
                    'given_left' => '3x/5',
                    'given_right' => '4/9',
                    'header' => [
                        ['title' => 'Left', 'terms' => [['num' => '3x', 'den' => '5']]],
                        ['title' => 'Right', 'terms' => [['num' => '4', 'den' => '9']]],
                    ],
                    'scale_tip' => 'a is 3x, b is 5, c is 4, d is 9. Cross: 3x × 9 = 5 × 4.',
                    'steps' => [
                        [
                            'key' => 'rule',
                            'type' => 'note_next',
                            'value' => 'ok',
                            'lead' => 'If a/b = c/d, then a × d = b × c.',
                            'mark' => 'Cross',
                            'button' => 'Cross it',
                            'ok_message' => 'Cross it. 3x meets 9, and 5 meets 4.',
                            'miss' => 'Read the rule, then cross.',
                            'before' => [
                                ['num' => '3x', 'den' => '5'],
                                ['op' => '=', 'num' => '4', 'den' => '9'],
                            ],
                            'after' => [
                                ['num' => '3x', 'den' => '5'],
                                ['op' => '=', 'num' => '4', 'den' => '9'],
                            ],
                            'done' => '3x/5 = 4/9. Cross: a × d = b × c.',
                        ],
                        [
                            'key' => 'products',
                            'type' => 'copy_line',
                            'hint' => '3x × 9 = 5 × 4. On each side of the cross, the two numbers can sit either way round.',
                            'miss' => '3x × 9 = 5 × 4. 4 × 5 is the same product.',
                            'from' => '3x/5 = 4/9',
                            'commute' => [['lt', 'rb'], ['lb', 'rt']],
                            'parts' => [
                                ['box' => 'lt', 'label' => 'Left top, 3x'],
                                ['text' => '×'],
                                ['box' => 'rb', 'label' => 'Right bottom, 9'],
                                ['eq' => true],
                                ['box' => 'lb', 'label' => 'Left bottom, 5'],
                                ['text' => '×'],
                                ['box' => 'rt', 'label' => 'Right top, 4'],
                            ],
                            'done' => '3x × 9 = 5 × 4',
                        ],
                        [
                            'key' => 'times',
                            'type' => 'copy_line',
                            'hint' => '3 × 9 = 27, so 27x. 5 × 4 = 20.',
                            'miss' => '27x = 20.',
                            'from' => '3x × 9 = 5 × 4',
                            'parts' => [
                                ['box' => 'coef', 'label' => '27x'],
                                ['eq' => true],
                                ['box' => 'rhs', 'label' => '20'],
                            ],
                            'done' => '27x = 20',
                        ],
                        [
                            'key' => 'x',
                            'type' => 'copy_line',
                            'hint' => 'Divide by 27. x = 20/27.',
                            'miss' => 'x = 20/27.',
                            'from' => '27x = 20',
                            'parts' => [
                                ['text' => 'x ='],
                                ['box' => 'x', 'label' => 'Value of x'],
                            ],
                            'done' => 'x = 20/27',
                        ],
                    ],
                    'tally' => '3x × 9 = 5 × 4, so 27x = 20 and x = 20/27.',
                ],
                'check' => [
                    'lt' => '3x',
                    'rb' => '9',
                    'lb' => '5',
                    'rt' => '4',
                    'coef' => '27x',
                    'rhs' => '20',
                    'x' => '20/27',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bracketCross(): array
    {
        return [
            'sort_order' => 2,
            'difficulty' => 'easy',
            'is_demo' => true,
            'question_text' => 'Cross (2x + 1)/5 = 1/2. The whole top crosses.',
            'answers' => [
                'mode' => 'balance',
                'variable_tip' => 'The whole numerator crosses, brackets and all. 2 × (2x + 1) = 5 × 1. Then open the brackets.',
                'play' => [
                    'given_left' => '(2x + 1)/5',
                    'given_right' => '1/2',
                    'header' => [
                        ['title' => 'Left', 'terms' => [['num' => '2x + 1', 'den' => '5']]],
                        ['title' => 'Right', 'terms' => [['num' => '1', 'den' => '2']]],
                    ],
                    'scale_tip' => 'Cross the whole top. 2 meets (2x + 1). 5 meets 1.',
                    'steps' => [
                        [
                            'key' => 'cross',
                            'type' => 'copy_line',
                            'hint' => '2 × (2x + 1) = 5 × 1. The 5 and the 1 can sit either way round.',
                            'miss' => '2(2x + 1) = 5 × 1. 1 × 5 is the same.',
                            'commute' => [['k5', 'k1']],
                            'parts' => [
                                ['box' => 'k2', 'label' => 'Right bottom'],
                                ['text' => '(2x + 1) ='],
                                ['box' => 'k5', 'label' => 'Left bottom'],
                                ['text' => '×'],
                                ['box' => 'k1', 'label' => 'Right top'],
                            ],
                            'done' => '2(2x + 1) = 5 × 1',
                        ],
                        [
                            'key' => 'open',
                            'type' => 'copy_line',
                            'hint' => '2 × 2x = 4x and 2 × 1 = 2. The right side is 5.',
                            'miss' => '4x + 2 = 5.',
                            'from' => '2(2x + 1) = 5',
                            'parts' => [
                                ['box' => 'open4', 'label' => '4x'],
                                ['text' => '+'],
                                ['box' => 'open2', 'label' => '2'],
                                ['eq' => true],
                                ['text' => '5'],
                            ],
                            'done' => '4x + 2 = 5',
                        ],
                        [
                            'key' => 'shift',
                            'type' => 'copy_line',
                            'hint' => 'Move 2. 5 − 2 = 3, so 4x = 3.',
                            'miss' => '4x = 3.',
                            'from' => '4x + 2 = 5',
                            'parts' => [
                                ['box' => 'four', 'label' => '4x'],
                                ['eq' => true],
                                ['box' => 'three', 'label' => '3'],
                            ],
                            'done' => '4x = 3',
                        ],
                        [
                            'key' => 'x',
                            'type' => 'copy_line',
                            'hint' => 'x = 3/4.',
                            'miss' => 'x = 3/4.',
                            'from' => '4x = 3',
                            'parts' => [
                                ['text' => 'x ='],
                                ['box' => 'x', 'label' => 'Value of x'],
                            ],
                            'done' => 'x = 3/4',
                        ],
                    ],
                    'tally' => '2(2x + 1) = 5, so 4x + 2 = 5, 4x = 3 and x = 3/4.',
                ],
                'check' => [
                    'k2' => '2',
                    'k5' => '5',
                    'k1' => '1',
                    'open4' => '4x',
                    'open2' => '2',
                    'four' => '4x',
                    'three' => '3',
                    'x' => '3/4',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mixedCross(): array
    {
        return [
            'sort_order' => 3,
            'difficulty' => 'medium',
            'is_demo' => true,
            'question_text' => '2x + 1/2 = (x + 1)/5. Make the left one fraction, then cross.',
            'answers' => [
                'mode' => 'balance',
                'variable_tip' => 'A whole term plus a fraction is not ready to cross. Write the whole term over the same denominator, add, then cross.',
                'play' => [
                    'given_left' => '2x + 1/2',
                    'given_right' => '(x + 1)/5',
                    'header' => [
                        ['title' => 'Left', 'terms' => [
                            ['num' => '2x'],
                            ['op' => '+', 'num' => '1', 'den' => '2'],
                        ]],
                        ['title' => 'Right', 'terms' => [['num' => 'x + 1', 'den' => '5']]],
                    ],
                    'scale_tip' => '2x is 2x/1. Multiply the top and the bottom by 2, then add 1/2.',
                    'steps' => [
                        [
                            'key' => 'join',
                            'type' => 'stacked_line',
                            'hint' => '2x × 2 = 4x, and the bottom is 2. Add the 1 that is already over 2. The right side stays (x + 1)/5.',
                            'miss' => '(4x + 1)/2 = (x + 1)/5.',
                            'action_label' => 'One fraction on the left',
                            'action' => [
                                ['title' => 'Left', 'terms' => [
                                    ['num' => '2x'],
                                    ['op' => '+', 'num' => '1', 'den' => '2'],
                                ]],
                                ['title' => 'Right', 'terms' => [['num' => 'x + 1', 'den' => '5']]],
                            ],
                            'left' => [
                                'den' => '2',
                                'pieces' => [
                                    ['box' => 'top4', 'label' => '2 × 2x, write 4x'],
                                    ['text' => '+ 1'],
                                ],
                            ],
                            'right' => [
                                'den' => '5',
                                'pieces' => [['text' => 'x + 1']],
                            ],
                            'done' => '(4x + 1)/2 = (x + 1)/5',
                        ],
                        [
                            'key' => 'cross',
                            'type' => 'copy_line',
                            'hint' => 'Now it is one fraction each side. 5(4x + 1) = 2(x + 1).',
                            'miss' => '5(4x + 1) = 2(x + 1).',
                            'from' => '(4x + 1)/2 = (x + 1)/5',
                            'parts' => [
                                ['box' => 'c5', 'label' => 'Right bottom'],
                                ['text' => '(4x + 1) ='],
                                ['box' => 'c2', 'label' => 'Left bottom'],
                                ['text' => '(x + 1)'],
                            ],
                            'done' => '5(4x + 1) = 2(x + 1)',
                        ],
                        [
                            'key' => 'open',
                            'type' => 'copy_line',
                            'hint' => '5 × 4x = 20x and 5 × 1 = 5. 2 × x = 2x and 2 × 1 = 2.',
                            'miss' => '20x + 5 = 2x + 2.',
                            'from' => '5(4x + 1) = 2(x + 1)',
                            'parts' => [
                                ['box' => 't20', 'label' => '20x'],
                                ['text' => '+'],
                                ['box' => 't5', 'label' => '5'],
                                ['eq' => true],
                                ['box' => 't2a', 'label' => '2x'],
                                ['text' => '+'],
                                ['box' => 't2b', 'label' => '2'],
                            ],
                            'done' => '20x + 5 = 2x + 2',
                        ],
                        [
                            'key' => 'shift',
                            'type' => 'copy_line',
                            'hint' => '20x − 2x = 18x. 2 − 5 = −3.',
                            'miss' => '18x = −3.',
                            'from' => '20x + 5 = 2x + 2',
                            'parts' => [
                                ['box' => 'coef18', 'label' => '18x'],
                                ['eq' => true],
                                ['box' => 'rhs3', 'label' => '−3'],
                            ],
                            'done' => '18x = −3',
                        ],
                        [
                            'key' => 'x',
                            'type' => 'copy_line',
                            'hint' => '−3 ÷ 18. Both signs: the minus stays one, then simplify. x = −1/6.',
                            'miss' => 'x = −1/6.',
                            'from' => '18x = −3',
                            'parts' => [
                                ['text' => 'x ='],
                                ['box' => 'x', 'label' => 'Value of x'],
                            ],
                            'done' => 'x = −1/6',
                        ],
                    ],
                    'tally' => '(4x + 1)/2 = (x + 1)/5, so 5(4x + 1) = 2(x + 1), 18x = −3 and x = −1/6.',
                ],
                'check' => [
                    'top4' => '4x',
                    'c5' => '5',
                    'c2' => '2',
                    't20' => '20x',
                    't5' => '5',
                    't2a' => '2x',
                    't2b' => '2',
                    'coef18' => '18x',
                    'rhs3' => '-3',
                    'x' => '-1/6',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tenSums(): array
    {
        return [
            'sort_order' => 4,
            'difficulty' => 'medium',
            'is_demo' => false,
            'question_text' => 'Ten sums. Cross a/b = c/d. If a side is a whole term plus a fraction, make one fraction first.',
            'answers' => [
                'mode' => 'fill_page',
                'variable_tip' => 'a × d = b × c. A bracket on top crosses as a whole. A whole term plus a fraction becomes one fraction before you cross.',
                'play' => [
                    'page_blurb' => 'Numerator above the line. Cross, then solve for x.',
                    'items' => [
                        $this->item('q1', '3x × 9 = 5 × 4, then divide.', [
                            ['num' => '3x', 'den' => '5'], ['eq' => true], ['num' => '4', 'den' => '9'],
                        ]),
                        $this->item('q2', 'The whole top crosses. 2(2x + 1) = 5 × 1.', [
                            ['num' => '2x + 1', 'den' => '5'], ['eq' => true], ['num' => '1', 'den' => '2'],
                        ]),
                        $this->item('q3', 'Write 2x as a fraction over 2, add 1/2, then cross.', [
                            ['num' => '2x'], ['op' => '+', 'num' => '1', 'den' => '2'], ['eq' => true], ['num' => 'x + 1', 'den' => '5'],
                        ]),
                        $this->item('q4', '4x × 3 = 2 × 7. Then simplify.', [
                            ['num' => '4x', 'den' => '7'], ['eq' => true], ['num' => '2', 'den' => '3'],
                        ]),
                        $this->item('q5', '2(x − 3) = 4 × 5.', [
                            ['num' => 'x − 3', 'den' => '4'], ['eq' => true], ['num' => '5', 'den' => '2'],
                        ]),
                        $this->item('q6', '5(3x − 1) = 2 × 4.', [
                            ['num' => '3x − 1', 'den' => '2'], ['eq' => true], ['num' => '4', 'den' => '5'],
                        ]),
                        $this->item('q7', 'Write x/3 and 1/2 over 6, add, then the denominators match.', [
                            ['num' => 'x', 'den' => '3'], ['op' => '+', 'num' => '1', 'den' => '2'], ['eq' => true], ['num' => '5', 'den' => '6'],
                        ]),
                        $this->item('q8', 'Write 2x over 3, add 1/3, then cross with (x + 2)/2.', [
                            ['num' => '2x'], ['op' => '+', 'num' => '1', 'den' => '3'], ['eq' => true], ['num' => 'x + 2', 'den' => '2'],
                        ]),
                        $this->item('q9', 'x is on the bottom. 5 × 3 = x × 2.', [
                            ['num' => '5', 'den' => 'x'], ['eq' => true], ['num' => '2', 'den' => '3'],
                        ]),
                        $this->item('q10', '4(2x − 1) = 3 × 3.', [
                            ['num' => '2x − 1', 'den' => '3'], ['eq' => true], ['num' => '3', 'den' => '4'],
                        ]),
                    ],
                ],
                'check' => [
                    'q1' => '20/27',
                    'q2' => '3/4',
                    'q3' => '-1/6',
                    'q4' => '7/6',
                    'q5' => '13',
                    'q6' => '13/15',
                    'q7' => '1',
                    'q8' => '4/9',
                    'q9' => '15/2',
                    'q10' => '13/8',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function variableDenominator(): array
    {
        return [
            'sort_order' => 5,
            'difficulty' => 'medium',
            'is_demo' => true,
            'question_text' => '1/x + 1/(2x) = 2/3. Make the denominators the same when x is on the bottom, then cross.',
            'answers' => [
                'mode' => 'balance',
                'variable_tip' => 'When x is in the denominator, make one denominator first. x and 2x share 2x. 1/x = 2/(2x). Add, then cross.',
                'play' => [
                    'given_left' => '1/x + 1/(2x)',
                    'given_right' => '2/3',
                    'header' => [
                        ['title' => 'Left', 'terms' => [
                            ['num' => '1', 'den' => 'x'],
                            ['op' => '+', 'num' => '1', 'den' => '2x'],
                        ]],
                        ['title' => 'Right', 'terms' => [['num' => '2', 'den' => '3']]],
                    ],
                    'scale_tip' => 'Common denominator 2x. Multiply the top and the bottom of 1/x by 2.',
                    'steps' => [
                        [
                            'key' => 'same',
                            'type' => 'stacked_line',
                            'hint' => '1/x becomes 2/(2x). The other fraction is already 1/(2x). Write both tops over 2x.',
                            'miss' => '2/(2x) + 1/(2x). The tops are 2 and 1.',
                            'action_label' => 'Same denominator 2x',
                            'action' => [
                                ['title' => 'Left', 'terms' => [
                                    ['factor' => '2', 'num' => '1', 'den' => 'x'],
                                    ['op' => '+', 'num' => '1', 'den' => '2x'],
                                ]],
                                ['title' => 'Right', 'terms' => [['num' => '2', 'den' => '3']]],
                            ],
                            'left' => [
                                'den' => '2x',
                                'pieces' => [
                                    ['box' => 't1', 'label' => '1 × 2'],
                                    ['text' => '+'],
                                    ['box' => 't2', 'label' => 'The 1 already over 2x'],
                                ],
                            ],
                            'right' => [
                                'den' => '3',
                                'pieces' => [['text' => '2']],
                            ],
                            'done' => '(2 + 1)/(2x) = 2/3',
                        ],
                        [
                            'key' => 'add',
                            'type' => 'stacked_line',
                            'hint' => '2 + 1 = 3. The denominator stays 2x.',
                            'miss' => '3/(2x) = 2/3.',
                            'from' => '(2 + 1)/(2x) = 2/3',
                            'left' => [
                                'den' => '2x',
                                'pieces' => [['box' => 'sum3', 'label' => '2 + 1']],
                            ],
                            'right' => [
                                'den' => '3',
                                'pieces' => [['text' => '2']],
                            ],
                            'done' => '3/(2x) = 2/3',
                        ],
                        [
                            'key' => 'cross',
                            'type' => 'copy_line',
                            'hint' => 'Cross. 3 × 3 = 2x × 2. The 2x and the 2 can sit either way round.',
                            'miss' => '3 × 3 = 2x × 2. 2 × 2x is the same.',
                            'commute' => [['p2x', 'p2']],
                            'from' => '3/(2x) = 2/3',
                            'parts' => [
                                ['box' => 'p3', 'label' => 'Left top'],
                                ['text' => '×'],
                                ['box' => 'p3b', 'label' => 'Right bottom'],
                                ['eq' => true],
                                ['box' => 'p2x', 'label' => 'Left bottom, 2x'],
                                ['text' => '×'],
                                ['box' => 'p2', 'label' => 'Right top'],
                            ],
                            'done' => '3 × 3 = 2x × 2',
                        ],
                        [
                            'key' => 'times',
                            'type' => 'copy_line',
                            'hint' => '9 = 4x.',
                            'miss' => '9 = 4x.',
                            'from' => '3 × 3 = 2x × 2',
                            'parts' => [
                                ['box' => 'nine', 'label' => '9'],
                                ['eq' => true],
                                ['box' => 'fourx', 'label' => '4x'],
                            ],
                            'done' => '9 = 4x',
                        ],
                        [
                            'key' => 'x',
                            'type' => 'copy_line',
                            'hint' => 'x = 9/4.',
                            'miss' => 'x = 9/4.',
                            'from' => '9 = 4x',
                            'parts' => [
                                ['text' => 'x ='],
                                ['box' => 'x', 'label' => 'Value of x'],
                            ],
                            'done' => 'x = 9/4',
                        ],
                    ],
                    'tally' => '2/(2x) + 1/(2x) = 3/(2x) = 2/3, so 9 = 4x and x = 9/4.',
                ],
                'check' => [
                    't1' => '2',
                    't2' => '1',
                    'sum3' => '3',
                    'p3' => '3',
                    'p3b' => '3',
                    'p2x' => '2x',
                    'p2' => '2',
                    'nine' => '9',
                    'fourx' => '4x',
                    'x' => '9/4',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notebookSet(): array
    {
        return [
            'sort_order' => 6,
            'difficulty' => 'hard',
            'is_demo' => false,
            'question_text' => 'Eight sums. x can sit in the denominator. Make one denominator, then cross.',
            'answers' => [
                'mode' => 'fill_page',
                'variable_tip' => 'If both sides are single fractions, cross straight away. If you add 1, write 1 as a fraction with the same denominator. If x is on the bottom, make those denominators the same first.',
                'play' => [
                    'page_blurb' => 'Numerator above the line. Same denominator when x is on the bottom, then cross.',
                    'items' => [
                        $this->item('q1', 'Cross straight away. 5(x − 2) = 3(x − 3).', [
                            ['num' => 'x − 2', 'den' => 'x − 3'], ['eq' => true], ['num' => '3', 'den' => '5'],
                        ]),
                        $this->item('q2', 'Write 1 as (x − 3)/(x − 3), add the tops, then cross with 3/2.', [
                            ['num' => 'x − 2', 'den' => 'x − 3'], ['op' => '+', 'num' => '1'], ['eq' => true], ['num' => '3', 'den' => '2'],
                        ]),
                        $this->item('q3', 'Both tops and bottoms have x. Cross (x − 2)(x − 7) = (x − 3)(x − 4).', [
                            ['num' => 'x − 2', 'den' => 'x − 3'], ['eq' => true], ['num' => 'x − 4', 'den' => 'x − 7'],
                        ]),
                        $this->item('q4', 'Common denominator 6x. 3/(6x) + 2/(6x).', [
                            ['num' => '1', 'den' => '2x'], ['op' => '+', 'num' => '1', 'den' => '3x'], ['eq' => true], ['num' => '5', 'den' => '6'],
                        ]),
                        $this->item('q5', 'Common denominator 2x. 2/(2x) − 1/(2x).', [
                            ['num' => '1', 'den' => 'x'], ['op' => '−', 'num' => '1', 'den' => '2x'], ['eq' => true], ['num' => '1', 'den' => '4'],
                        ]),
                        $this->item('q6', 'Common denominator 6x. 2/(6x) + 3/(6x).', [
                            ['num' => '1', 'den' => '3x'], ['op' => '+', 'num' => '1', 'den' => '2x'], ['eq' => true], ['num' => '1', 'den' => '2'],
                        ]),
                        $this->item('q7', 'The 1/(4x) terms cancel. What is left is 1/(2x) = 1/6.', [
                            ['num' => '1', 'den' => '2x'], ['op' => '+', 'num' => '1', 'den' => '4x'], ['op' => '−', 'num' => '1', 'den' => '4x'], ['eq' => true], ['num' => '1', 'den' => '6'],
                        ]),
                        $this->item('q8', 'Common denominator 2x. 4/(2x) + 1/(2x) = 5/(2x).', [
                            ['num' => '2', 'den' => 'x'], ['op' => '+', 'num' => '1', 'den' => '2x'], ['eq' => true], ['num' => '5', 'den' => '6'],
                        ]),
                    ],
                ],
                'check' => [
                    'q1' => '1/2',
                    'q2' => '1',
                    'q3' => '1',
                    'q4' => '1',
                    'q5' => '2',
                    'q6' => '5/3',
                    'q7' => '3',
                    'q8' => '3',
                ],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $terms
     * @return array<string, mixed>
     */
    private function item(string $key, string $hint, array $terms): array
    {
        return [
            'key' => $key,
            'variable' => 'x',
            'hint' => $hint,
            'terms' => $terms,
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-6')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
