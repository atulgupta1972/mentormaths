<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'balance-equation-basic-2')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'balance-equation-basic-2',
            'title' => 'Balance the equation — Basic 2',
            'heading' => 'Balance the equation — Basic 2',
            'kind' => 'balance_equation',
            'status' => 'published',
            'accent' => 'rose',
            'method_blurb' => '2x is one term — keep it together. First tidy the extra numbers, then divide both sides by the same number so x stands alone. Open brackets and work the right-hand side before you take a number across.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 4,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $exercises = [
            [
                'sort_order' => 1,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Solve 2x = 14. Divide both sides by the same number.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => '2x means 2 × x. Divide both sides by 2 so the 2 cancels and x is alone.',
                    'play' => [
                        'given_left' => '2x',
                        'given_right' => '14',
                        'scale_tip' => 'Both pans already match. Divide both sides by the same number so the scale stays level.',
                        'steps' => [
                            [
                                'key' => 'divide',
                                'type' => 'divide',
                                'left' => '2x',
                                'right' => '14',
                                'hint' => 'What number sits in front of x? Divide both sides by that number.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '14 ÷ 2 = ? That is x.',
                            ],
                        ],
                        'tally' => '2x ÷ 2 = 14 ÷ 2, so x = 7. Check: 2 × 7 = 14.',
                    ],
                    'check' => ['divide' => '2', 'x' => '7'],
                ],
            ],
            [
                'sort_order' => 2,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve 2x + 8 = 14. Keep 2x as one term.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => '2x is one term. Do not split it yet. First take +8 across so you have 2x = 14 − 8. Then divide.',
                    'play' => [
                        'given_left' => '2x + 8',
                        'given_right' => '14',
                        'scale_tip' => 'Treat 2x as one term. Tidy the extra number first, then divide both sides.',
                        'steps' => [
                            [
                                'key' => 'isolate',
                                'type' => 'isolate_term',
                                'keep' => '2x',
                                'frozen' => '14',
                                'op' => '−',
                                'hint' => '2x is one term. Take +8 to the other side as −8. Fill 2x = 14 − □.',
                            ],
                            [
                                'key' => 'term',
                                'type' => 'term_value',
                                'keep' => '2x',
                                'hint' => 'Work out 14 − 8. Keep 2x together.',
                            ],
                            [
                                'key' => 'divide',
                                'type' => 'divide',
                                'left' => '2x',
                                'right_from' => 'term',
                                'right' => '6',
                                'hint' => 'Now x = 6 / 2. Divide both sides by 2.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '6 ÷ 2 = ? That is x.',
                            ],
                        ],
                        'tally' => '2x = 14 − 8 = 6, then x = 6 / 2 = 3. Check: 2×3 + 8 = 14.',
                    ],
                    'check' => ['isolate' => '8', 'term' => '6', 'divide' => '2', 'x' => '3'],
                ],
            ],
            [
                'sort_order' => 3,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Solve 8 − 2x = 14 − (13 + 14). Open the bracket first.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Open the bracket, then finish the right-hand side. After that, take the loose 8 across. Keep −2x as one term. Last, divide by 2.',
                    'play' => [
                        'given_left' => '8 − 2x',
                        'given_right' => '14 − (13 + 14)',
                        'scale_tip' => 'Open brackets and finish the right-hand side before you move any number.',
                        'steps' => [
                            [
                                'key' => 'bracket',
                                'type' => 'open_bracket',
                                'expression' => '13 + 14',
                                'hint' => 'Open the bracket first: 13 + 14 = ?',
                            ],
                            [
                                'key' => 'rhs',
                                'type' => 'rhs_value',
                                'keep' => '8 − 2x',
                                'struck' => '14 − (13 + 14)',
                                'working' => '14 − 27',
                                'hint' => 'RHS next: 14 − 27 = ? Mind the sign.',
                            ],
                            [
                                'key' => 'take',
                                'type' => 'take_number',
                                'keep' => '−2x',
                                'frozen' => '−13',
                                'op' => '−',
                                'hint' => 'Take the loose 8 across. Subtract 8 from both sides: −2x = −13 − □.',
                            ],
                            [
                                'key' => 'term',
                                'type' => 'term_value',
                                'keep' => '−2x',
                                'hint' => 'Work out −13 − 8. Keep −2x together.',
                            ],
                            [
                                'key' => 'divide',
                                'type' => 'divide',
                                'left' => '2x',
                                'right' => '21',
                                'rewrite_note' => '−2x = −21 is the same as 2x = 21 (both sides negative). Now divide by 2.',
                                'rewrite_left' => '2x',
                                'rewrite_right' => '21',
                                'hint' => 'Last: divide both sides by 2.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => '21 ÷ 2 = ? You can write 21/2 or 10.5.',
                            ],
                        ],
                        'tally' => 'Bracket 27, RHS −13, take 8 → −2x = −21, so 2x = 21 and x = 21/2. Check: 8 − 2×(21/2) = 8 − 21 = −13, and 14 − 27 = −13.',
                    ],
                    'check' => [
                        'bracket' => '27',
                        'rhs' => '-13',
                        'take' => '8',
                        'term' => '-21',
                        'divide' => ['2', '-2'],
                        'x' => ['21/2', '10.5'],
                    ],
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
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-2')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
