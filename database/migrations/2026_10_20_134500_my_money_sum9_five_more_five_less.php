<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sum 9: ₹2 is 5 more than ₹5; ₹10 is 5 less than ₹5; total coins 45.
 * Accept x as ₹5 (x=15), ₹2 (x=20), or ₹10 (x=10).
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

        // Coins: 20 of ₹2, 15 of ₹5, 10 of ₹10 → money 40+75+100 = 215
        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 9)
            ->update([
                'difficulty' => 'hard',
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The number of ₹ 2 coins is 5 more than the number of ₹ 5 coins, and the number of ₹ 10 coins is 5 less than the number of ₹ 5 coins. If there are 45 coins in all, find the total money.',
                'answers' => json_encode([
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
                    'variable_tip' => 'Choose what x stands for. If x = number of ₹ 5 coins, then ₹ 2 coins = x + 5 and ₹ 10 coins = x − 5. Write the equation for 45 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        // x = ₹5 → x = 15
                        '(x+5)+x+(x-5)=45',
                        'x+5+x+x-5=45',
                        'x+5+x+(x-5)=45',
                        '(x+5)+x+(x-5)=45',
                        '3x=45',
                        '3x+0=45',
                        // x = ₹2 → x = 20
                        'x+(x-5)+(x-10)=45',
                        'x+(x-5)+(x-10)=45',
                        '3x-15=45',
                        '3x=60',
                        // x = ₹10 → x = 10
                        '(x+10)+(x+5)+x=45',
                        'x+10+x+5+x=45',
                        '3x+15=45',
                        '3x=30',
                    ],
                    'rs2' => [
                        'denomination' => '2',
                        'count_x' => ['x+5', '5+x', 'x', 'x+10'],
                        'count' => '20',
                        'total' => '40',
                    ],
                    'rs5' => [
                        'denomination' => '5',
                        'count_x' => ['x', 'x-5', 'x+5'],
                        'count' => '15',
                        'total' => '75',
                    ],
                    'rs10' => [
                        'denomination' => '10',
                        'count_x' => ['x-5', 'x-10', 'x'],
                        'count' => '10',
                        'total' => '100',
                    ],
                    'grand_count' => '45',
                    'grand_total' => '215',
                    'x_value' => ['15', '20', '10'],
                ]),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
