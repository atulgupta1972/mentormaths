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

        $answers = [
            'mode' => 'balance',
            'variable_tip' => 'Keep 6/5 as it is. The 2/5 in front of x moves across by flipping to 5/2. Then cancel.',
            'play' => [
                'given_left' => '2x / 5',
                'given_right' => '6 / 5',
                'scale_tip' => '6/5 stays. 2/5 in front of x moves to the other side as 5/2.',
                'steps' => [
                    [
                        'key' => 'flip',
                        'type' => 'keep_flip',
                        'keep' => '6 / 5',
                        'flip_from' => '2 / 5',
                        'hint' => 'Do not break 6/5. Write the flip of 2/5 in the empty boxes.',
                    ],
                    [
                        'key' => 'cut',
                        'type' => 'cut',
                        'pair_key' => 'cut_b',
                        'cut_left' => '6 ÷ 2',
                        'cut_right' => '5 ÷ 5',
                        'hint' => 'Now you have 6/5 × 5/2. Cut 6 with 2, and the two 5s.',
                    ],
                    [
                        'key' => 'x',
                        'type' => 'solve',
                        'rewrite_note' => 'After the cuts, multiply what is left.',
                        'hint' => '3 × 1 = ? That is x.',
                    ],
                ],
                'tally' => 'Keep 6/5. Flip 2/5 to 5/2. So x = 6/5 × 5/2. Cut 6/2 = 3 and 5/5 = 1, so x = 3. Check: 2×3 / 5 = 6/5.',
            ],
            'check' => [
                'flip_n' => '5',
                'flip_d' => '2',
                'cut' => '3',
                'cut_b' => '1',
                'x' => '3',
            ],
        ];

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 5)
            ->update([
                'question_text' => 'Solve 2x / 5 = 6 / 5. Keep 6/5. Flip 2/5.',
                'answers' => json_encode($answers),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }
};
