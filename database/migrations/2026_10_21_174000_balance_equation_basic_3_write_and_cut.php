<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-3')->value('id');
        if (! $cardId) {
            return;
        }

        $now = now();
        $updates = [
            3 => [
                'question_text' => 'Solve 3x / 5 = 5 / 3. Write x as a fraction first.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Do not multiply 3x × 3 in your head. First write x = (other top × this bottom) / (number in front of x × other bottom). Then see if anything cuts.',
                    'play' => [
                        'given_left' => '3x / 5',
                        'given_right' => '5 / 3',
                        'scale_tip' => 'Write x as a fraction. Tops and bottoms can be in either order.',
                        'steps' => [
                            [
                                'key' => 'frac',
                                'type' => 'write_frac',
                                'num_keys' => ['n1', 'n2'],
                                'den_keys' => ['d1', 'd2'],
                                'hint' => 'Top: 5 and 5. Bottom: 3 (in front of x) and 3. Do not multiply them yet.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'hint' => 'Nothing cuts. 5 × 5 over 3 × 3 is 25/9.',
                            ],
                        ],
                        'tally' => 'x = 5×5 / (3×3) = 25/9. Check: 3×(25/9)/5 = 75/45 = 5/3.',
                    ],
                    'check' => [
                        'frac_num' => ['5', '5'],
                        'frac_den' => ['3', '3'],
                        'x' => '25/9',
                    ],
                ],
            ],
            4 => [
                'question_text' => 'Solve 4x / 9 = 8 / 3. Write x as a fraction, then cut.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Write x = 8 × 9 / (4 × 3). Do not multiply 8×9 or 4×3. Cut 8 with 4, and 9 with 3. Then 2 × 3 is x.',
                    'play' => [
                        'given_left' => '4x / 9',
                        'given_right' => '8 / 3',
                        'scale_tip' => 'Write x as a fraction, then cancel. Do not multiply the products first.',
                        'steps' => [
                            [
                                'key' => 'frac',
                                'type' => 'write_frac',
                                'num_keys' => ['n1', 'n2'],
                                'den_keys' => ['d1', 'd2'],
                                'hint' => 'x = 8 × 9 over 4 × 3 (either order on top or bottom). Do not multiply yet.',
                            ],
                            [
                                'key' => 'cut',
                                'type' => 'cut',
                                'pair_key' => 'cut_b',
                                'cut_left' => '8 ÷ 4',
                                'cut_right' => '9 ÷ 3',
                                'hint' => 'Cut 8 with 4, and 9 with 3. What does each pair become?',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'rewrite_note' => 'After the cuts, multiply what is left.',
                                'hint' => '2 × 3 = ? That is x.',
                            ],
                        ],
                        'tally' => 'x = 8×9 / (4×3). Cut 8/4 = 2 and 9/3 = 3, so x = 2 × 3 = 6. Check: 4×6 / 9 = 24/9 = 8/3.',
                    ],
                    'check' => [
                        'frac_num' => ['8', '9'],
                        'frac_den' => ['4', '3'],
                        'cut' => '2',
                        'cut_b' => '3',
                        'x' => '6',
                    ],
                ],
            ],
            5 => [
                'question_text' => 'Solve 2x / 5 = 6 / 5. Write x as a fraction, then cut.',
                'answers' => [
                    'mode' => 'balance',
                    'play' => [
                        'given_left' => '2x / 5',
                        'given_right' => '6 / 5',
                        'scale_tip' => 'Write x as a fraction, then cancel matching numbers.',
                        'steps' => [
                            [
                                'key' => 'frac',
                                'type' => 'write_frac',
                                'num_keys' => ['n1', 'n2'],
                                'den_keys' => ['d1', 'd2'],
                                'hint' => 'x = 6 × 5 over 2 × 5. Do not multiply yet.',
                            ],
                            [
                                'key' => 'cut',
                                'type' => 'cut',
                                'pair_key' => 'cut_b',
                                'cut_left' => '6 ÷ 2',
                                'cut_right' => '5 ÷ 5',
                                'hint' => 'Cut 6 with 2, and the two 5s.',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'solve',
                                'rewrite_note' => 'After the cuts, multiply what is left.',
                                'hint' => '3 × 1 = ? That is x.',
                            ],
                        ],
                        'tally' => 'x = 6×5 / (2×5). Cut 6/2 = 3 and 5/5 = 1, so x = 3. Check: 2×3 / 5 = 6/5.',
                    ],
                    'check' => [
                        'frac_num' => ['6', '5'],
                        'frac_den' => ['2', '5'],
                        'cut' => '3',
                        'cut_b' => '1',
                        'x' => '3',
                    ],
                ],
            ],
        ];

        foreach ($updates as $sort => $exercise) {
            DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sort)
                ->update([
                    'question_text' => $exercise['question_text'],
                    'answers' => json_encode($exercise['answers']),
                    'updated_at' => $now,
                ]);
        }
    }

    public function down(): void
    {
        //
    }
};
