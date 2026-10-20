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

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'my-money-table',
            'title' => 'My Money table',
            'heading' => 'My Money table',
            'kind' => 'table_fill',
            'status' => 'published',
            'accent' => 'amber',
            'method_blurb' => 'For money word problems, make a table with denomination, coins, and total value. When counts are in a ratio or given by conditions, write coins in terms of x, form an equation for total coins, solve for x, then fill the number of each coin and the money.',
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
            // 10 — conditions (not a simple ratio)
            [
                'sort_order' => 10,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'A purse has coins of ₹ 2, ₹ 5 and ₹ 10. The number of ₹ 2 coins is 5 more than the number of ₹ 5 coins, and the number of ₹ 5 coins is half the number of ₹ 10 coins. If there are 25 coins in all, find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
                    'variable_tip' => 'Let x = number of ₹ 5 coins. Then ₹ 2 coins = x + 5, and ₹ 10 coins = 2x (because ₹ 5 is half of ₹ 10). Write Coins (in x), make the equation for 25 coins, find x, then fill counts and money.',
                    'equation_prompt' => 'Equation for total coins',
                    'x_prompt' => 'Value of x',
                    'equation' => [
                        '(x+5)+x+2x=25',
                        'x+5+x+2x=25',
                        '4x+5=25',
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
            [
                'sort_order' => 11,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are such that ₹ 2 coins are 5 more than ₹ 5 coins, and ₹ 5 coins are half of ₹ 10 coins. Altogether there are 29 coins. Find the total money.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $ratioVar2510,
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
