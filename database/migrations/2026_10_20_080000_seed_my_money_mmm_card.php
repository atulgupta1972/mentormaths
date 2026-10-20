<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'my-money-table')->exists()) {
            return;
        }

        $basicSchema = [
            'columns' => [
                ['key' => 'label', 'label' => 'Denomination', 'fixed' => true],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs2', 'label' => '₹ 2'],
                ['key' => 'rs5', 'label' => '₹ 5'],
                ['key' => 'rs10', 'label' => '₹ 10'],
            ],
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        // From sum 3 onward: student fills denomination (number in ₹).
        $fillDenom2510 = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs2', 'label' => ''],
                ['key' => 'rs5', 'label' => ''],
                ['key' => 'rs10', 'label' => ''],
            ],
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        $fillDenom2050100 = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs20', 'label' => ''],
                ['key' => 'rs50', 'label' => ''],
                ['key' => 'rs100', 'label' => ''],
            ],
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

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

        $noteValueSchema = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'value_x', 'label' => 'Value (in x)'],
                ['key' => 'count', 'label' => 'No. of notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs50', 'label' => ''],
                ['key' => 'rs100', 'label' => ''],
                ['key' => 'rs500', 'label' => ''],
            ],
            'show_grand_count' => true,
            'grand_count_label' => 'Total notes',
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'my-money-table',
            'title' => 'My Money table',
            'heading' => 'My Money table',
            'kind' => 'table_fill',
            'status' => 'published',
            'accent' => 'amber',
            'method_blurb' => 'For money word problems, make a table with denomination, amounts in x, and totals. For coin/note counts use an equation on total coins. When the ratio is of total values of notes, write values as parts of x, equate to total money, then find the number of notes = value ÷ denomination.',
            'table_schema' => json_encode($basicSchema),
            'sort_order' => 2,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $now = now();
        $exercises = [
            // 1 — demo basics ₹2 / ₹5 / ₹10
            [
                'sort_order' => 1,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Riya has 4 coins of ₹ 2, 3 coins of ₹ 5 and 2 coins of ₹ 10. How much money does she have in all?',
                'answers' => [
                    'rs2' => ['count' => '4', 'total' => '8'],
                    'rs5' => ['count' => '3', 'total' => '15'],
                    'rs10' => ['count' => '2', 'total' => '20'],
                    'grand_total' => '43',
                ],
            ],
            // 2 — easy
            [
                'sort_order' => 2,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Aman has 6 coins of ₹ 2, 5 coins of ₹ 5 and 3 coins of ₹ 10. Find the total money with him.',
                'answers' => [
                    'rs2' => ['count' => '6', 'total' => '12'],
                    'rs5' => ['count' => '5', 'total' => '25'],
                    'rs10' => ['count' => '3', 'total' => '30'],
                    'grand_total' => '67',
                ],
            ],
            // 3 — easy (student fills denomination)
            [
                'sort_order' => 3,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Neha collected 8 coins of ₹ 2, 2 coins of ₹ 5 and 4 coins of ₹ 10. How much money did she collect?',
                'answers' => [
                    'table_schema' => $fillDenom2510,
                    'rs2' => ['denomination' => '2', 'count' => '8', 'total' => '16'],
                    'rs5' => ['denomination' => '5', 'count' => '2', 'total' => '10'],
                    'rs10' => ['denomination' => '10', 'count' => '4', 'total' => '40'],
                    'grand_total' => '66',
                ],
            ],
            // 4 — medium (still counts given)
            [
                'sort_order' => 4,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Kabir has 10 coins of ₹ 2, 7 coins of ₹ 5 and 5 coins of ₹ 10. Make the money table and find the total amount.',
                'answers' => [
                    'table_schema' => $fillDenom2510,
                    'rs2' => ['denomination' => '2', 'count' => '10', 'total' => '20'],
                    'rs5' => ['denomination' => '5', 'count' => '7', 'total' => '35'],
                    'rs10' => ['denomination' => '10', 'count' => '5', 'total' => '50'],
                    'grand_total' => '105',
                ],
            ],
            // 5 — basic with ₹20 / ₹50 / ₹100
            [
                'sort_order' => 5,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Meera has 3 notes of ₹ 20, 2 notes of ₹ 50 and 1 note of ₹ 100. How much money does she have?',
                'answers' => [
                    'table_schema' => $fillDenom2050100,
                    'rs20' => ['denomination' => '20', 'count' => '3', 'total' => '60'],
                    'rs50' => ['denomination' => '50', 'count' => '2', 'total' => '100'],
                    'rs100' => ['denomination' => '100', 'count' => '1', 'total' => '100'],
                    'grand_total' => '260',
                ],
            ],
            // 6 — ratio with variable equation
            [
                'sort_order' => 6,
                'difficulty' => 'medium',
                'is_demo' => false,
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
            [
                'sort_order' => 7,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are in the ratio 1/2 : 1/3 : 1/4. The total number of coins is 39. Find how much money there is in all.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'You may write coins as x/2, x/3, x/4 (then x/2+x/3+x/4=39) or clear the ratio with LCM 12 to get 6x, 4x, 3x (then 6x+4x+3x=39). Find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '6x+4x+3x=39',
                        '13x=39',
                        '(6+4+3)x=39',
                        'x/2+x/3+x/4=39',
                        '(x/2)+(x/3)+(x/4)=39',
                    ],
                    'rs2' => [
                        'denomination' => '2',
                        'count_x' => ['6x', 'x/2', '(1/2)x', '1/2x'],
                        'count' => '18',
                        'total' => '36',
                    ],
                    'rs5' => [
                        'denomination' => '5',
                        'count_x' => ['4x', 'x/3', '(1/3)x', '1/3x'],
                        'count' => '12',
                        'total' => '60',
                    ],
                    'rs10' => [
                        'denomination' => '10',
                        'count_x' => ['3x', 'x/4', '(1/4)x', '1/4x'],
                        'count' => '9',
                        'total' => '90',
                    ],
                    'grand_count' => '39',
                    'grand_total' => '186',
                    'x_value' => ['3', '36'],
                ],
            ],
            [
                'sort_order' => 8,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The number of ₹ 2 coins is 3 times the number of ₹ 5 coins, and the number of ₹ 10 coins is half the number of ₹ 5 coins. If there are 45 coins in all, find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Choose what x stands for. If x = number of ₹ 5 coins, then ₹ 2 coins = 3x and ₹ 10 coins = x/2. If x = number of ₹ 10 coins, then ₹ 5 coins = 2x and ₹ 2 coins = 6x. Write the equation for 45 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '3x+x+x/2=45',
                        '3x+x+(1/2)x=45',
                        '(3x)+x+(x/2)=45',
                        '9x=90',
                        '6x+2x+x=90',
                        '6x+2x+x=45',
                        '9x=45',
                        '(6x)+(2x)+x=45',
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
                ],
            ],
            [
                'sort_order' => 9,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The number of ₹ 2 coins is 5 more than the number of ₹ 5 coins, and the number of ₹ 10 coins is 5 less than the number of ₹ 5 coins. If there are 45 coins in all, find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Choose what x stands for. If x = number of ₹ 5 coins, then ₹ 2 coins = x + 5 and ₹ 10 coins = x − 5. Write the equation for 45 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '(x+5)+x+(x-5)=45',
                        'x+5+x+x-5=45',
                        'x+5+x+(x-5)=45',
                        '3x=45',
                        'x+(x-5)+(x-10)=45',
                        '3x-15=45',
                        '3x=60',
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
                ],
            ],
            // 10–12 — note value ratios (₹50, ₹100, ₹500)
            [
                'sort_order' => 10,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are such that their total values are in the ratio 4 : 5 : 6. If the total amount is ₹ 7500, find the number of notes of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $noteValueSchema,
                    'variable_tip' => 'The ratio is of total values (not of the number of notes). Let the values be 4x, 5x and 6x. Write the equation for total money, find x, fill Total value, then No. of notes = Total value ÷ denomination.',
                    'equation_prompt' => 'Equation for total money',
                    'x_prompt' => 'Value of x',
                    'equation' => ['4x+5x+6x=7500', '15x=7500', '(4+5+6)x=7500'],
                    'rs50' => ['denomination' => '50', 'value_x' => '4x', 'count' => '40', 'total' => '2000'],
                    'rs100' => ['denomination' => '100', 'value_x' => '5x', 'count' => '25', 'total' => '2500'],
                    'rs500' => ['denomination' => '500', 'value_x' => '6x', 'count' => '6', 'total' => '3000'],
                    'grand_count' => '71',
                    'grand_total' => '7500',
                    'x_value' => '500',
                ],
            ],
            [
                'sort_order' => 11,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are such that their total values are in the ratio 3 : 4 : 5. If the total amount is ₹ 6000, find the number of notes of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $noteValueSchema,
                    'variable_tip' => 'Values are 3x, 4x and 5x. Equation for ₹ 6000 → find x → Total value of each → No. of notes = value ÷ 50, 100 or 500.',
                    'equation_prompt' => 'Equation for total money',
                    'x_prompt' => 'Value of x',
                    'equation' => ['3x+4x+5x=6000', '12x=6000', '(3+4+5)x=6000'],
                    'rs50' => ['denomination' => '50', 'value_x' => '3x', 'count' => '30', 'total' => '1500'],
                    'rs100' => ['denomination' => '100', 'value_x' => '4x', 'count' => '20', 'total' => '2000'],
                    'rs500' => ['denomination' => '500', 'value_x' => '5x', 'count' => '5', 'total' => '2500'],
                    'grand_count' => '55',
                    'grand_total' => '6000',
                    'x_value' => '500',
                ],
            ],
            [
                'sort_order' => 12,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are such that their total values are in the ratio 2 : 3 : 5. If the total amount is ₹ 6000, find the number of notes of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $noteValueSchema,
                    'variable_tip' => 'Values are 2x, 3x and 5x. Make the equation with total money ₹ 6000, find x, then find the number of notes of each denomination.',
                    'equation_prompt' => 'Equation for total money',
                    'x_prompt' => 'Value of x',
                    'equation' => ['2x+3x+5x=6000', '10x=6000', '(2+3+5)x=6000'],
                    'rs50' => ['denomination' => '50', 'value_x' => '2x', 'count' => '24', 'total' => '1200'],
                    'rs100' => ['denomination' => '100', 'value_x' => '3x', 'count' => '18', 'total' => '1800'],
                    'rs500' => ['denomination' => '500', 'value_x' => '5x', 'count' => '6', 'total' => '3000'],
                    'grand_count' => '48',
                    'grand_total' => '6000',
                    'x_value' => '600',
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
        $cardId = DB::table('mmm_cards')->where('slug', 'my-money-table')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_card_syllabus_chapter')->where('mmm_card_id', $cardId)->delete();
        DB::table('student_mmm_progress')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
