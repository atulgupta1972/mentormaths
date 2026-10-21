<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'balance-the-equation')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'balance-the-equation',
            'title' => 'Balance the equation — Basic 1',
            'heading' => 'Balance the equation — Basic 1',
            'kind' => 'balance_equation',
            'status' => 'published',
            'accent' => 'rose',
            'method_blurb' => 'An equation is a balance scale: left side = right side. Whatever you add or subtract on one side, do the same on the other so it stays level. To free x, add the opposite of the number stuck to x, cancel, then read x. This card is + and − with integers. Next, Basic 2 uses terms and divide.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 3,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exercises = [
            [
                'sort_order' => 1,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Keep both sides equal. Solve x + 5 = 10.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'x has +5 stuck to it. Add −5 on both sides so +5 and −5 cancel. The scale stays level.',
                    'play' => [
                        'given_left' => 'x + 5',
                        'given_right' => '10',
                        'steps' => [
                            [
                                'key' => 'add',
                                'type' => 'balance',
                                'hint' => 'Type the opposite of +5 in the boxes. The same number appears on both sides.',
                            ],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'right',
                                'hint' => '5 + (−5) = 0, so the left becomes x + 0. Only fill the right box: 10 + (−5) = ?',
                            ],
                        ],
                        'tally' => 'x + 0 = 5, so x = 5. Check: 5 + 5 = 10.',
                    ],
                    'check' => ['add' => '-5', 'impact' => '5'],
                ],
            ],
            [
                'sort_order' => 2,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Keep both sides equal. Solve x − 5 = 10.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'x has −5 stuck to it. Add +5 on both sides so they cancel.',
                    'play' => [
                        'given_left' => 'x − 5',
                        'given_right' => '10',
                        'steps' => [
                            [
                                'key' => 'add',
                                'type' => 'balance',
                                'hint' => 'Type the opposite of −5 (that is +5, or just 5) on both sides.',
                            ],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'right',
                                'hint' => '−5 + 5 = 0, so the left becomes x + 0. Only fill the right box: 10 + 5 = ?',
                            ],
                        ],
                        'tally' => 'x + 0 = 15, so x = 15. Check: 15 − 5 = 10.',
                    ],
                    'check' => ['add' => '5', 'impact' => '15'],
                ],
            ],
            [
                'sort_order' => 3,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve x + 10 = 23. Do it in two steps: balance, then find x.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => 'x + 10',
                        'given_right' => '23',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +10 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'right',
                                'hint' => '10 + (−10) = 0, so the left is x + 0. Only fill the right box.',
                            ],
                        ],
                        'tally' => 'x + 0 = 13, so x = 13. Check: 13 + 10 = 23.',
                    ],
                    'check' => ['add' => '-10', 'impact' => '13'],
                ],
            ],
            [
                'sort_order' => 4,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve x − 7 = 18.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => 'x − 7',
                        'given_right' => '18',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of −7 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'right',
                                'hint' => '−7 + 7 = 0, so the left is x + 0. Only fill the right box: 18 + 7 = ?',
                            ],
                        ],
                        'tally' => 'x + 0 = 25, so x = 25. Check: 25 − 7 = 18.',
                    ],
                    'check' => ['add' => '7', 'impact' => '25'],
                ],
            ],
            [
                'sort_order' => 5,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve x + 9 = 4. x can be a negative integer.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => 'x + 9',
                        'given_right' => '4',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +9 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'right',
                                'hint' => '9 + (−9) = 0, so the left is x + 0. Only fill the right box. Mind the sign.',
                            ],
                        ],
                        'tally' => 'x + 0 = −5, so x = −5. Check: −5 + 9 = 4.',
                    ],
                    'check' => ['add' => '-9', 'impact' => '-5'],
                ],
            ],
            [
                'sort_order' => 6,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve x − 3 = −8.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => 'x − 3',
                        'given_right' => '−8',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of −3 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'right',
                                'hint' => '−3 + 3 = 0, so the left is x + 0. Only fill the right box: −8 + 3 = ?',
                            ],
                        ],
                        'tally' => 'x + 0 = −5, so x = −5. Check: −5 − 3 = −8.',
                    ],
                    'check' => ['add' => '3', 'impact' => '-5'],
                ],
            ],
            [
                'sort_order' => 7,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Now x is on the right. Solve 20 = x + 30.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Same rule: add the opposite of +30 on both sides. x will be left alone on the right.',
                    'play' => [
                        'given_left' => '20',
                        'given_right' => 'x + 30',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +30 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'left',
                                'hint' => '30 + (−30) = 0, so the right becomes x + 0. Only fill the left box: 20 + (−30) = ?',
                            ],
                        ],
                        'tally' => '−10 = x + 0, so x = −10. Check: −10 + 30 = 20.',
                    ],
                    'check' => ['add' => '-30', 'impact' => '-10'],
                ],
            ],
            [
                'sort_order' => 8,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Solve 15 = x − 5.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => '15',
                        'given_right' => 'x − 5',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of −5 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => 'x + 0',
                                'box_side' => 'left',
                                'hint' => '−5 + 5 = 0, so the right is x + 0. Only fill the left box: 15 + 5 = ?',
                            ],
                        ],
                        'tally' => '20 = x + 0, so x = 20. Check: 20 − 5 = 15.',
                    ],
                    'check' => ['add' => '5', 'impact' => '20'],
                ],
            ],
            [
                'sort_order' => 9,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'x is not written first. Solve 8 + x = 2.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => '8 + x',
                        'given_right' => '2',
                        'steps' => [
                            ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +8 on both sides.'],
                            [
                                'key' => 'impact',
                                'type' => 'impact',
                                'x_expr' => '0 + x',
                                'box_side' => 'right',
                                'hint' => '8 + (−8) = 0, so the left becomes 0 + x. Only fill the right box: 2 + (−8) = ?',
                            ],
                        ],
                        'tally' => '0 + x = −6, so x = −6. Check: 8 + (−6) = 2.',
                    ],
                    'check' => ['add' => '-8', 'impact' => '-6'],
                ],
            ],
            [
                'sort_order' => 10,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'First tidy the numbers, then free x. Solve x − 10 = 55 + 25.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Step 1: add 55 + 25. Step 2: add the opposite of −10. Step 3: write x.',
                    'play' => [
                        'given_left' => 'x − 10',
                        'given_right' => '55 + 25',
                        'steps' => [
                            [
                                'key' => 'rhs',
                                'type' => 'simplify_right',
                                'hint' => 'Add the two numbers on the right first.',
                            ],
                            [
                                'key' => 'move',
                                'type' => 'move',
                                'frozen_from' => 'rhs',
                                'op' => '+',
                                'rewrite_left' => 'x − 10',
                                'hint' => 'Now the equation is x − 10 = 80. Add the opposite of −10 (write 10).',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => 'Add those two numbers.',
                            ],
                        ],
                        'tally' => 'Check: 90 − 10 = 80 and 55 + 25 = 80. Both sides match.',
                    ],
                    'check' => ['rhs' => '80', 'move' => '10', 'x' => '90'],
                ],
            ],
            [
                'sort_order' => 11,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'First tidy the right side. Solve x + 6 = 20 − 9.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => 'x + 6',
                        'given_right' => '20 − 9',
                        'steps' => [
                            ['key' => 'rhs', 'type' => 'simplify_right', 'hint' => 'Work out 20 − 9.'],
                            [
                                'key' => 'move',
                                'type' => 'move',
                                'frozen_from' => 'rhs',
                                'op' => '+',
                                'rewrite_left' => 'x + 6',
                                'hint' => 'Now the equation is x + 6 = 11. Add the opposite of +6 (write −6).',
                            ],
                            ['key' => 'x', 'type' => 'solve', 'hint' => '11 + (−6) = ?'],
                        ],
                        'tally' => 'Check: 5 + 6 = 11 and 20 − 9 = 11.',
                    ],
                    'check' => ['rhs' => '11', 'move' => '-6', 'x' => '5'],
                ],
            ],
            [
                'sort_order' => 12,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Numbers are on the left this time. Solve 18 + 7 = x − 5.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => '18 + 7',
                        'given_right' => 'x − 5',
                        'steps' => [
                            ['key' => 'lhs', 'type' => 'simplify_left', 'hint' => 'Add 18 + 7 on the left first.'],
                            [
                                'key' => 'move',
                                'type' => 'move',
                                'frozen_from' => 'lhs',
                                'op' => '+',
                                'rewrite_left' => 'x − 5',
                                'hint' => 'Now the equation is x − 5 = 25. Add the opposite of −5 (write 5).',
                            ],
                            ['key' => 'x', 'type' => 'solve', 'hint' => '25 + 5 = ?'],
                        ],
                        'tally' => 'Check: 18 + 7 = 25 and 30 − 5 = 25.',
                    ],
                    'check' => ['lhs' => '25', 'move' => '5', 'x' => '30'],
                ],
            ],
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
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-the-equation')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
