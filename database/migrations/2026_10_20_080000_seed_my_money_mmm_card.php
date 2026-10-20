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

        $ratioSchema2510 = [
            'columns' => [
                ['key' => 'label', 'label' => 'Denomination', 'fixed' => true],
                ['key' => 'ratio', 'label' => 'Ratio of coins'],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs2', 'label' => '₹ 2'],
                ['key' => 'rs5', 'label' => '₹ 5'],
                ['key' => 'rs10', 'label' => '₹ 10'],
            ],
            'show_grand_count' => true,
            'grand_count_label' => 'Total coins',
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        $ratioSchema2050100 = [
            'columns' => [
                ['key' => 'label', 'label' => 'Denomination', 'fixed' => true],
                ['key' => 'ratio', 'label' => 'Ratio of coins'],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs20', 'label' => '₹ 20'],
                ['key' => 'rs50', 'label' => '₹ 50'],
                ['key' => 'rs100', 'label' => '₹ 100'],
            ],
            'show_grand_count' => true,
            'grand_count_label' => 'Total coins',
            'show_grand_total' => true,
            'grand_total_label' => 'Total money',
        ];

        $notesSchema = [
            'columns' => [
                ['key' => 'label', 'label' => 'Denomination', 'fixed' => true],
                ['key' => 'count', 'label' => 'No. of coins/notes'],
                ['key' => 'total', 'label' => 'Total value'],
            ],
            'rows' => [
                ['key' => 'rs20', 'label' => '₹ 20'],
                ['key' => 'rs50', 'label' => '₹ 50'],
                ['key' => 'rs100', 'label' => '₹ 100'],
            ],
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
            'method_blurb' => 'For money word problems, first make a table: one row per denomination, then No. of coins/notes × denomination = Total value. Add the totals to get the money. When coins are in a ratio, add a Ratio column and use Total coins to find each count.',
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
            // 3 — easy
            [
                'sort_order' => 3,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Neha collected 8 coins of ₹ 2, 2 coins of ₹ 5 and 4 coins of ₹ 10. How much money did she collect?',
                'answers' => [
                    'rs2' => ['count' => '8', 'total' => '16'],
                    'rs5' => ['count' => '2', 'total' => '10'],
                    'rs10' => ['count' => '4', 'total' => '40'],
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
                    'rs2' => ['count' => '10', 'total' => '20'],
                    'rs5' => ['count' => '7', 'total' => '35'],
                    'rs10' => ['count' => '5', 'total' => '50'],
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
                    'table_schema' => $notesSchema,
                    'rs20' => ['count' => '3', 'total' => '60'],
                    'rs50' => ['count' => '2', 'total' => '100'],
                    'rs100' => ['count' => '1', 'total' => '100'],
                    'grand_total' => '260',
                ],
            ],
            // 6 — ratio 2:3:4, total coins 27 → 6, 9, 12 → 12+45+120 = 177
            [
                'sort_order' => 6,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are in the ratio 2 : 3 : 4. If there are 27 coins in all, find the total money.',
                'answers' => [
                    'table_schema' => $ratioSchema2510,
                    'method_tip' => 'Write the ratio parts in the Ratio column. Total parts = 2 + 3 + 4 = 9. Each part = 27 ÷ 9 = 3 coins. Then fill No. of coins and Total value.',
                    'rs2' => ['ratio' => '2', 'count' => '6', 'total' => '12'],
                    'rs5' => ['ratio' => '3', 'count' => '9', 'total' => '45'],
                    'rs10' => ['ratio' => '4', 'count' => '12', 'total' => '120'],
                    'grand_count' => '27',
                    'grand_total' => '177',
                ],
            ],
            // 7 — ratio 2:3:4, total coins 36 → 8, 12, 16 → 16+60+160 = 236
            [
                'sort_order' => 7,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Coins of ₹ 2, ₹ 5 and ₹ 10 are in the ratio 2 : 3 : 4. The total number of coins is 36. Find how much money there is in all.',
                'answers' => [
                    'table_schema' => $ratioSchema2510,
                    'method_tip' => 'Ratio parts 2 : 3 : 4 → 9 parts. One part = 36 ÷ 9 = 4. So counts are 8, 12 and 16.',
                    'rs2' => ['ratio' => '2', 'count' => '8', 'total' => '16'],
                    'rs5' => ['ratio' => '3', 'count' => '12', 'total' => '60'],
                    'rs10' => ['ratio' => '4', 'count' => '16', 'total' => '160'],
                    'grand_count' => '36',
                    'grand_total' => '236',
                ],
            ],
            // 8 — ratio 2:3:4, total coins 45 → 10, 15, 20 → 20+75+200 = 295
            [
                'sort_order' => 8,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'There are coins of ₹ 2, ₹ 5 and ₹ 10 in the ratio 2 : 3 : 4. Altogether there are 45 coins. Make the table and find the total money.',
                'answers' => [
                    'table_schema' => $ratioSchema2510,
                    'method_tip' => '9 equal parts make 45 coins → each part is 5 coins.',
                    'rs2' => ['ratio' => '2', 'count' => '10', 'total' => '20'],
                    'rs5' => ['ratio' => '3', 'count' => '15', 'total' => '75'],
                    'rs10' => ['ratio' => '4', 'count' => '20', 'total' => '200'],
                    'grand_count' => '45',
                    'grand_total' => '295',
                ],
            ],
            // 9 — ₹20 / ₹50 / ₹100 ratio 2:3:4, total coins 27 → 6, 9, 12 → 120+450+1200 = 1770
            [
                'sort_order' => 9,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Notes of ₹ 20, ₹ 50 and ₹ 100 are in the ratio 2 : 3 : 4. If there are 27 notes in all, find the total money.',
                'answers' => [
                    'table_schema' => $ratioSchema2050100,
                    'method_tip' => 'Same ratio idea: 2 + 3 + 4 = 9 parts. One part = 27 ÷ 9 = 3 notes.',
                    'rs20' => ['ratio' => '2', 'count' => '6', 'total' => '120'],
                    'rs50' => ['ratio' => '3', 'count' => '9', 'total' => '450'],
                    'rs100' => ['ratio' => '4', 'count' => '12', 'total' => '1200'],
                    'grand_count' => '27',
                    'grand_total' => '1770',
                ],
            ],
            // 10 — ₹20 / ₹50 / ₹100 ratio 2:3:4, total coins 45 → 10, 15, 20 → 200+750+2000 = 2950
            [
                'sort_order' => 10,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'Notes of ₹ 20, ₹ 50 and ₹ 100 are in the ratio 2 : 3 : 4. There are 45 notes altogether. Find the total amount of money.',
                'answers' => [
                    'table_schema' => $ratioSchema2050100,
                    'method_tip' => '9 parts → 45 notes → each part = 5 notes. Counts: 10, 15 and 20.',
                    'rs20' => ['ratio' => '2', 'count' => '10', 'total' => '200'],
                    'rs50' => ['ratio' => '3', 'count' => '15', 'total' => '750'],
                    'rs100' => ['ratio' => '4', 'count' => '20', 'total' => '2000'],
                    'grand_count' => '45',
                    'grand_total' => '2950',
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
