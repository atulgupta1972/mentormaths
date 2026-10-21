<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sums 13–15: ₹2 / ₹5 / ₹10 coins; relations between total values; equation at value level.
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
                ['key' => 'value_x', 'label' => 'Value (in x)'],
                ['key' => 'count', 'label' => 'No. of coins'],
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

        $now = now();

        $exercises = [
            13 => [
                'difficulty' => 'hard',
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The total value of ₹ 2 coins is half the total value of ₹ 5 coins, and the total value of ₹ 5 coins is ₹ 100 more than the total value of ₹ 10 coins. If the total amount is ₹ 1650, find the number of coins of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
                    'variable_tip' => 'Work with total values, not the number of coins. Let x = total value of ₹ 5 coins. Then ₹ 2 value = x/2 and ₹ 10 value = x − 100. Write the equation for ₹ 1650, find x, fill Total value, then No. of coins = value ÷ denomination.',
                    'equation_prompt' => 'Equation for total money',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        'x/2+x+(x-100)=1650',
                        'x/2+x+x-100=1650',
                        '5x/2-100=1650',
                        '(x+100)/2+(x+100)+x=1650',
                        'x+2x+(2x-100)=1650',
                        'x+2x+2x-100=1650',
                        '5x-100=1650',
                    ],
                    'rs2' => [
                        'denomination' => '2',
                        'value_x' => ['x/2', '(1/2)x', '1/2x', '(x+100)/2', 'x/2+50', 'x'],
                        'count' => '175',
                        'total' => '350',
                    ],
                    'rs5' => [
                        'denomination' => '5',
                        'value_x' => ['x', 'x+100', '100+x', '2x'],
                        'count' => '140',
                        'total' => '700',
                    ],
                    'rs10' => [
                        'denomination' => '10',
                        'value_x' => ['x-100', '-100+x', 'x', '2x-100'],
                        'count' => '60',
                        'total' => '600',
                    ],
                    'grand_count' => '375',
                    'grand_total' => '1650',
                    'x_value' => ['700', '600', '350'],
                ],
            ],
            14 => [
                'difficulty' => 'hard',
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The total value of ₹ 10 coins is half the total value of ₹ 5 coins, and the total value of ₹ 5 coins is ₹ 150 more than the total value of ₹ 2 coins. If the total amount is ₹ 1350, find the number of coins of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
                    'variable_tip' => 'Let x = total value of ₹ 5 coins. Then ₹ 10 value = x/2 and ₹ 2 value = x − 150. Equation for ₹ 1350 → find x → Total value of each → No. of coins = value ÷ 2, 5 or 10.',
                    'equation_prompt' => 'Equation for total money',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '(x-150)+x+x/2=1350',
                        'x-150+x+x/2=1350',
                        '5x/2-150=1350',
                        'x+(x+150)+(x+150)/2=1350',
                        'x+2x+(2x-150)=1350',
                        '5x-150=1350',
                    ],
                    'rs2' => [
                        'denomination' => '2',
                        'value_x' => ['x-150', '-150+x', 'x', '2x-150'],
                        'count' => '225',
                        'total' => '450',
                    ],
                    'rs5' => [
                        'denomination' => '5',
                        'value_x' => ['x', 'x+150', '150+x', '2x'],
                        'count' => '120',
                        'total' => '600',
                    ],
                    'rs10' => [
                        'denomination' => '10',
                        'value_x' => ['x/2', '(1/2)x', '1/2x', '(x+150)/2', 'x/2+75', 'x'],
                        'count' => '30',
                        'total' => '300',
                    ],
                    'grand_count' => '375',
                    'grand_total' => '1350',
                    'x_value' => ['600', '450', '300'],
                ],
            ],
            15 => [
                'difficulty' => 'hard',
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The total value of ₹ 5 coins is half the total value of ₹ 2 coins, and the total value of ₹ 2 coins is ₹ 200 more than the total value of ₹ 10 coins. If the total amount is ₹ 1800, find the number of coins of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
                    'variable_tip' => 'Let x = total value of ₹ 2 coins. Then ₹ 5 value = x/2 and ₹ 10 value = x − 200. Make the equation with total money ₹ 1800, find x, then find the number of coins of each denomination.',
                    'equation_prompt' => 'Equation for total money',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        'x+x/2+(x-200)=1800',
                        'x+x/2+x-200=1800',
                        '5x/2-200=1800',
                        '2x+x+(2x-200)=1800',
                        '5x-200=1800',
                        '(x+200)+(x+200)/2+x=1800',
                    ],
                    'rs2' => [
                        'denomination' => '2',
                        'value_x' => ['x', '2x', 'x+200', '200+x'],
                        'count' => '400',
                        'total' => '800',
                    ],
                    'rs5' => [
                        'denomination' => '5',
                        'value_x' => ['x/2', '(1/2)x', '1/2x', 'x', '(x+200)/2', 'x/2+100'],
                        'count' => '80',
                        'total' => '400',
                    ],
                    'rs10' => [
                        'denomination' => '10',
                        'value_x' => ['x-200', '-200+x', '2x-200', 'x'],
                        'count' => '60',
                        'total' => '600',
                    ],
                    'grand_count' => '540',
                    'grand_total' => '1800',
                    'x_value' => ['800', '400', '600'],
                ],
            ],
        ];

        foreach ($exercises as $sortOrder => $exercise) {
            $existing = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->first();

            $payload = [
                'difficulty' => $exercise['difficulty'],
                'is_demo' => false,
                'question_text' => $exercise['question_text'],
                'answers' => json_encode($exercise['answers']),
                'updated_at' => $now,
            ];

            if ($existing) {
                DB::table('mmm_card_exercises')->where('id', $existing->id)->update($payload);
            } else {
                DB::table('mmm_card_exercises')->insert($payload + [
                    'mmm_card_id' => $cardId,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'my-money-table')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->whereIn('sort_order', [13, 14, 15])
            ->delete();
    }
};
