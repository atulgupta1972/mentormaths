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
        if (DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 6)->exists()) {
            return;
        }

        $now = now();
        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 6,
            'difficulty' => 'medium',
            'is_demo' => false,
            'question_text' => 'Five sums on this page. Solve each in your copy, then write x. It checks as soon as you enter the answer.',
            'answers' => json_encode([
                'mode' => 'fill_page',
                'variable_tip' => 'If the numerator is a bracket, do not cut. Write x as a fraction or multiply both sides to clear the denominator, then cut matching numbers.',
                'play' => [
                    'page_blurb' => 'Solve in your copy. Keep (2x − 2) as one piece. Clear the denominator, then cut if you can. Write x. If you cannot get it, ask your teacher to look at your working.',
                    'items' => [
                        [
                            'key' => 'q1',
                            'equation' => '(2x − 2) / 4 = 1 / 8',
                            'left' => '(2x − 2) / 4',
                            'right' => '1 / 8',
                            'hint' => 'Do not cut the 4. Multiply both sides by 4 so 2x − 2 = 4/8. Tidy 4/8, take −2 across, then divide by 2.',
                        ],
                        [
                            'key' => 'q2',
                            'equation' => '(x + 3) / 4 = 5 / 2',
                            'left' => '(x + 3) / 4',
                            'right' => '5 / 2',
                            'hint' => 'Write x + 3 = 4 × 5 / 2. Cut 4 with 2 first, then take 3 across.',
                        ],
                        [
                            'key' => 'q3',
                            'equation' => '(3x − 6) / 5 = 3 / 5',
                            'left' => '(3x − 6) / 5',
                            'right' => '3 / 5',
                            'hint' => 'Same denominator on both sides: 3x − 6 = 3. Then take −6 across and divide by 3.',
                        ],
                        [
                            'key' => 'q4',
                            'equation' => '2x / 9 = 4 / 15',
                            'left' => '2x / 9',
                            'right' => '4 / 15',
                            'hint' => 'Write x = 4 × 9 / (2 × 15). Cut 4 with 2, then tidy what is left.',
                        ],
                        [
                            'key' => 'q5',
                            'equation' => '(x − 1) / 5 = 3 / 10',
                            'left' => '(x − 1) / 5',
                            'right' => '3 / 10',
                            'hint' => 'Multiply both sides by 5: x − 1 = 15/10. Tidy 15/10, then take −1 across.',
                        ],
                    ],
                ],
                'check' => [
                    'q1' => ['5/4', '1.25'],
                    'q2' => '7',
                    'q3' => '3',
                    'q4' => ['6/5', '1.2'],
                    'q5' => ['5/2', '2.5'],
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-3')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 6)->delete();
    }
};
