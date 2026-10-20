<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sum 7: ratio 1/2 : 1/3 : 1/4 (→ 6:4:3), total coins 39, money 186.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'my-money-table')->value('id');
        if (! $cardId) {
            return;
        }

        $ratioVar2510 = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'count_x', 'label' => 'Coins (in x)'],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs2', 'label' => ''],
                ['key' => 'rs5', 'label' => ''],
                ['key' => 'rs10', 'label' => ''],
            ],
            'show_grand_count' => true,
            'grand_count_label' => 'Total coins',
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        // 1/2 : 1/3 : 1/4 → multiply by LCM 12 → 6 : 4 : 3
        // 6x+4x+3x=39 → 13x=39 → x=3 → coins 18, 12, 9 → money 36+60+90=186
        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 7)
            ->update([
                'difficulty' => 'medium',
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are in the ratio 1/2 : 1/3 : 1/4. The total number of coins is 39. Find how much money there is in all.',
                'answers' => json_encode([
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Ratio 1/2 : 1/3 : 1/4. Multiply each part by LCM of 2, 3, 4 (that is 12) to get 6 : 4 : 3. So coins = 6x, 4x and 3x. Write the equation for 39 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '6x+4x+3x=39',
                        '13x=39',
                        '(6+4+3)x=39',
                        '4x+6x+3x=39',
                        '3x+4x+6x=39',
                    ],
                    'rs2' => ['denomination' => '2', 'count_x' => '6x', 'count' => '18', 'total' => '36'],
                    'rs5' => ['denomination' => '5', 'count_x' => '4x', 'count' => '12', 'total' => '60'],
                    'rs10' => ['denomination' => '10', 'count_x' => '3x', 'count' => '9', 'total' => '90'],
                    'grand_count' => '39',
                    'grand_total' => '186',
                    'x_value' => '3',
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
