<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sum 8: ₹2 = 3×₹5, ₹10 = ½×₹5, total coins 45.
 * Accept x = ₹5 (x=10), x = ₹10 (x=5), or x = ₹2 (x=30).
 */
return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'my-money-table')->value('id');
        if (! $cardId) {
            return;
        }

        $schema = [
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

        // Coins: 30 of ₹2, 10 of ₹5, 5 of ₹10 → money 60+50+50 = 160
        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 8)
            ->update([
                'difficulty' => 'hard',
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The number of ₹ 2 coins is 3 times the number of ₹ 5 coins, and the number of ₹ 10 coins is half the number of ₹ 5 coins. If there are 45 coins in all, find the total money.',
                'answers' => json_encode([
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
                    'variable_tip' => 'Choose what x stands for. If x = number of ₹ 5 coins, then ₹ 2 coins = 3x and ₹ 10 coins = x/2. If x = number of ₹ 10 coins, then ₹ 5 coins = 2x and ₹ 2 coins = 6x. Write the equation for 45 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        // x = ₹5 coins → x = 10
                        '3x+x+x/2=45',
                        '3x+x+(1/2)x=45',
                        '3x+x+x/2=45',
                        '(3x)+x+(x/2)=45',
                        '9x=90',
                        '6x+2x+x=90',
                        // x = ₹10 coins → x = 5
                        '6x+2x+x=45',
                        '9x=45',
                        '(6x)+(2x)+x=45',
                        // x = ₹2 coins → x = 30
                        'x+x/3+x/6=45',
                        '(x)+(x/3)+(x/6)=45',
                        '3x=90',
                        '(3/2)x=45',
                    ],
                    'rs2' => [
                        'denomination' => '2',
                        'count_x' => ['3x', '6x', 'x'],
                        'count' => '30',
                        'total' => '60',
                    ],
                    'rs5' => [
                        'denomination' => '5',
                        'count_x' => ['x', '2x', 'x/3', '(1/3)x', '1/3x'],
                        'count' => '10',
                        'total' => '50',
                    ],
                    'rs10' => [
                        'denomination' => '10',
                        'count_x' => ['x/2', '(1/2)x', '1/2x', 'x', 'x/6', '(1/6)x', '1/6x'],
                        'count' => '5',
                        'total' => '50',
                    ],
                    'grand_count' => '45',
                    'grand_total' => '160',
                    'x_value' => ['10', '5', '30'],
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
