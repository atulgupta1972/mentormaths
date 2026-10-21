<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'balance-equation-basic-3')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'balance-equation-basic-3',
            'title' => 'Balance the equation — Basic 3',
            'heading' => 'Balance the equation — Basic 3',
            'kind' => 'balance_equation',
            'status' => 'published',
            'accent' => 'rose',
            'method_blurb' => 'When x is over a number, multiply both sides by that denominator. If the numerator is a bracket, you cannot cut — the denominator belongs to the whole bracket. When both sides are fractions, cross-multiply: each numerator times the other denominator.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 5,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exercises = [
            [
                'sort_order' => 1,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Solve x / 2 = 10. Multiply both sides by 2.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'x is divided by 2. Multiply both sides by 2 so the 2 cancels and x is alone.',
                    'play' => [
                        'given_left' => 'x / 2',
                        'given_right' => '10',
                        'scale_tip' => 'Both sides stay equal. Multiply both sides by the same number — the denominator.',
                        'steps' => [
                            [
                                'key' => 'multiply',
                                'type' => 'multiply',
                                'left' => 'x / 2',
                                'right' => '10',
                                'hint' => 'What number is under x? Multiply both sides by that number.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '10 × 2 = ? That is x.',
                            ],
                        ],
                        'tally' => '(x / 2) × 2 = 10 × 2, so x = 20. Check: 20 / 2 = 10.',
                    ],
                    'check' => ['multiply' => '2', 'x' => '20'],
                ],
            ],
            [
                'sort_order' => 2,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Solve (x + 2) / 2 = 10. You cannot cut the 2.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'The 2 divides the whole bracket (x + 2), not x alone. You cannot cut the 2 — that would wrongly give x = 10. Multiply both sides by 2 first: x + 2 = 20, then x = 18.',
                    'play' => [
                        'given_left' => '(x + 2) / 2',
                        'given_right' => '10',
                        'scale_tip' => 'The denominator belongs to the whole (x + 2). Do not cut. Multiply both sides by 2.',
                        'steps' => [
                            [
                                'key' => 'multiply',
                                'type' => 'multiply',
                                'left' => '(x + 2) / 2',
                                'right' => '10',
                                'warning' => 'Do not cut the 2. (x + 2) / 2 is not x. Multiply both sides by 2 so the 2 cancels the whole bracket.',
                                'hint' => 'Multiply both sides by 2. The 2 cancels with the denominator.',
                            ],
                            [
                                'key' => 'inner',
                                'type' => 'term_value',
                                'keep' => 'x + 2',
                                'hint' => '10 × 2 = ? Now you have x + 2 = that number.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => 'x + 2 = 20, so x = ?',
                            ],
                        ],
                        'tally' => '(x + 2) / 2 × 2 = 10 × 2, so x + 2 = 20 and x = 18. Check: (18 + 2) / 2 = 10.',
                    ],
                    'check' => ['multiply' => '2', 'inner' => '20', 'x' => '18'],
                ],
            ],
            [
                'sort_order' => 3,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve 3x / 5 = 5 / 3. Cross-multiply.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Both sides are fractions. Cross-multiply: each numerator times the other denominator. (You can also multiply both sides by 5 first to remove the left denominator.) 3x × 3 = 5 × 5.',
                    'play' => [
                        'given_left' => '3x / 5',
                        'given_right' => '5 / 3',
                        'scale_tip' => 'Cross-multiply: 3x times 3, and 5 times 5.',
                        'steps' => [
                            [
                                'key' => 'cross',
                                'type' => 'cross',
                                'pair_key' => 'cross_b',
                                'left_num' => '3x',
                                'right_num' => '5',
                                'hint' => 'Left numerator × right denominator, right numerator × left denominator.',
                            ],
                            [
                                'key' => 'product',
                                'type' => 'term_value',
                                'keep' => '9x',
                                'hint' => '3x × 3 = 9x. Now 5 × 5 = ?',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '9x = 25, so x = 25/9.',
                            ],
                        ],
                        'tally' => '3x × 3 = 5 × 5, so 9x = 25 and x = 25/9. Check: 3×(25/9)/5 = 75/45 = 5/3.',
                    ],
                    'check' => ['cross' => '3', 'cross_b' => '5', 'product' => '25', 'x' => '25/9'],
                ],
            ],
            [
                'sort_order' => 4,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve 4x / 9 = 8 / 3. Cross-multiply, then find x.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => '4x / 9',
                        'given_right' => '8 / 3',
                        'scale_tip' => 'Cross-multiply, then divide to free x.',
                        'steps' => [
                            [
                                'key' => 'cross',
                                'type' => 'cross',
                                'pair_key' => 'cross_b',
                                'left_num' => '4x',
                                'right_num' => '8',
                                'hint' => '4x times the other denominator, 8 times the other denominator.',
                            ],
                            [
                                'key' => 'product',
                                'type' => 'term_value',
                                'keep' => '12x',
                                'hint' => '4x × 3 = 12x. Now 8 × 9 = ?',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '12x = 72, so x = 72 / 12.',
                            ],
                        ],
                        'tally' => '4x × 3 = 8 × 9, so 12x = 72 and x = 6. Check: 4×6 / 9 = 24/9 = 8/3.',
                    ],
                    'check' => ['cross' => '3', 'cross_b' => '9', 'product' => '72', 'x' => '6'],
                ],
            ],
            [
                'sort_order' => 5,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Solve 2x / 5 = 6 / 5. Cross-multiply.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => '2x / 5',
                        'given_right' => '6 / 5',
                        'scale_tip' => 'Same denominator on both sides still works with cross-multiply.',
                        'steps' => [
                            [
                                'key' => 'cross',
                                'type' => 'cross',
                                'pair_key' => 'cross_b',
                                'left_num' => '2x',
                                'right_num' => '6',
                                'hint' => '2x times 5, and 6 times 5.',
                            ],
                            [
                                'key' => 'product',
                                'type' => 'term_value',
                                'keep' => '10x',
                                'hint' => '2x × 5 = 10x. Now 6 × 5 = ?',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '10x = 30, so x = ?',
                            ],
                        ],
                        'tally' => '2x × 5 = 6 × 5, so 10x = 30 and x = 3. Check: 2×3 / 5 = 6/5.',
                    ],
                    'check' => ['cross' => '5', 'cross_b' => '5', 'product' => '30', 'x' => '3'],
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
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-3')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
