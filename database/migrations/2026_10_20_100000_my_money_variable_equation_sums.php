<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * My Money sums 6+: teach equation with x (Coins in x → equation → find x → counts),
 * plus a condition sum (₹2 is 5 more than ₹5; ₹5 is half of ₹10).
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

        $ratioVar2050100 = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'count_x', 'label' => 'Coins (in x)'],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs20', 'label' => ''],
                ['key' => 'rs50', 'label' => ''],
                ['key' => 'rs100', 'label' => ''],
            ],
            'show_grand_count' => true,
            'grand_count_label' => 'Total coins',
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        $conditionSchema = $ratioVar2510;

        $now = now();

        $updates = [
            6 => [
                'difficulty' => 'medium',
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are in the ratio 2 : 3 : 4. If there are 27 coins in all, find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Let the common multiple be x. Then the numbers of coins are 2x, 3x and 4x. Write the equation for total coins, find x, then fill No. of coins and Total value.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => ['2x+3x+4x=27', '9x=27', '(2+3+4)x=27'],
                    'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                    'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                    'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
                    'grand_count' => '27',
                    'grand_total' => '177',
                    'x_value' => '3',
                ],
            ],
            7 => [
                'difficulty' => 'medium',
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are in the ratio 2 : 3 : 4. The total number of coins is 36. Find how much money there is in all.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Again: coins = 2x, 3x, 4x. Make the equation with total coins 36, solve for x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => ['2x+3x+4x=36', '9x=36', '(2+3+4)x=36'],
                    'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '8', 'total' => '16'],
                    'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '12', 'total' => '60'],
                    'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '16', 'total' => '160'],
                    'grand_count' => '36',
                    'grand_total' => '236',
                    'x_value' => '4',
                ],
            ],
            8 => [
                'difficulty' => 'hard',
                'question_text' => 'There are coins of ₹ 2, ₹ 5 and ₹ 10 in the ratio 2 : 3 : 4. Altogether there are 45 coins. Make the equation, find x, then find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Write 2x, 3x, 4x in Coins (in x). Equation → find x → No. of coins → Total value.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => ['2x+3x+4x=45', '9x=45', '(2+3+4)x=45'],
                    'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '10', 'total' => '20'],
                    'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '15', 'total' => '75'],
                    'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '20', 'total' => '200'],
                    'grand_count' => '45',
                    'grand_total' => '295',
                    'x_value' => '5',
                ],
            ],
            9 => [
                'difficulty' => 'hard',
                'question_text' => 'Notes of ₹ 20, ₹ 50 and ₹ 100 are in the ratio 2 : 3 : 4. If there are 27 notes in all, find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2050100,
                    'variable_tip' => 'Same method with notes: 2x, 3x, 4x. Write the equation, find x, then fill counts and total money.',
                    'equation_prompt' => 'Equation for total notes',
                    'x_prompt' => 'Value of x',
                    'equation' => ['2x+3x+4x=27', '9x=27', '(2+3+4)x=27'],
                    'rs20' => ['denomination' => '20', 'count_x' => '2x', 'count' => '6', 'total' => '120'],
                    'rs50' => ['denomination' => '50', 'count_x' => '3x', 'count' => '9', 'total' => '450'],
                    'rs100' => ['denomination' => '100', 'count_x' => '4x', 'count' => '12', 'total' => '1200'],
                    'grand_count' => '27',
                    'grand_total' => '1770',
                    'x_value' => '3',
                ],
            ],
            10 => [
                'difficulty' => 'hard',
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The number of ₹ 2 coins is 5 more than the number of ₹ 5 coins, and the number of ₹ 5 coins is half the number of ₹ 10 coins. If there are 25 coins in all, find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $conditionSchema,
                    'variable_tip' => 'Let x = number of ₹ 5 coins. Then ₹ 2 coins = x + 5, and ₹ 10 coins = 2x (because ₹ 5 is half of ₹ 10). Write Coins (in x), make the equation for 25 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '(x+5)+x+2x=25',
                        'x+5+x+2x=25',
                        '4x+5=25',
                        '(x+5)+x+(2x)=25',
                        '5+x+x+2x=25',
                    ],
                    'rs2' => ['denomination' => '2', 'count_x' => ['x+5', '5+x'], 'count' => '10', 'total' => '20'],
                    'rs5' => ['denomination' => '5', 'count_x' => 'x', 'count' => '5', 'total' => '25'],
                    'rs10' => ['denomination' => '10', 'count_x' => '2x', 'count' => '10', 'total' => '100'],
                    'grand_count' => '25',
                    'grand_total' => '145',
                    'x_value' => '5',
                ],
            ],
        ];

        foreach ($updates as $sortOrder => $data) {
            DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->update([
                    'difficulty' => $data['difficulty'],
                    'question_text' => $data['question_text'],
                    'answers' => json_encode($data['answers']),
                    'updated_at' => $now,
                ]);
        }

        // Optional second condition practice (sum 11).
        $exists11 = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 11)
            ->exists();

        if (! $exists11) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => 11,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are such that ₹ 2 coins are 5 more than ₹ 5 coins, and ₹ 5 coins are half of ₹ 10 coins. Altogether there are 29 coins. Find the total money.',
                'answers' => json_encode([
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $conditionSchema,
                    'variable_tip' => 'Same relations: let x = ₹ 5 coins, so ₹ 2 = x + 5 and ₹ 10 = 2x. Now total coins = 29. Make the equation, find x, then fill the table.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '(x+5)+x+2x=29',
                        'x+5+x+2x=29',
                        '4x+5=29',
                        '5+x+x+2x=29',
                    ],
                    'rs2' => ['denomination' => '2', 'count_x' => ['x+5', '5+x'], 'count' => '11', 'total' => '22'],
                    'rs5' => ['denomination' => '5', 'count_x' => 'x', 'count' => '6', 'total' => '30'],
                    'rs10' => ['denomination' => '10', 'count_x' => '2x', 'count' => '12', 'total' => '120'],
                    'grand_count' => '29',
                    'grand_total' => '172',
                    'x_value' => '6',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('mmm_cards')->where('id', $cardId)->update([
            'method_blurb' => 'For money word problems, make a table with denomination, coins, and total value. When counts are in a ratio or given by conditions, write coins in terms of x, form an equation for total coins, solve for x, then fill the number of each coin and the money.',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
