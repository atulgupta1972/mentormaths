<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sums 10–12: notes ₹50, ₹100, ₹500; ratio of total values; find number of notes.
 * (4:5:6 with ₹6000 does not give whole ₹500 notes, so that sum uses ₹7500.)
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

        $now = now();

        $exercises = [
            // 10 — value ratio 4:5:6, total 7500 → x=500; values 2000,2500,3000; notes 40,25,6
            10 => [
                'difficulty' => 'hard',
                'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are such that their total values are in the ratio 4 : 5 : 6. If the total amount is ₹ 7500, find the number of notes of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
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
            // 11 — value ratio 3:4:5, total 6000 → x=500; values 1500,2000,2500; notes 30,20,5
            11 => [
                'difficulty' => 'hard',
                'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are such that their total values are in the ratio 3 : 4 : 5. If the total amount is ₹ 6000, find the number of notes of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
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
            // 12 — value ratio 2:3:5, total 6000 → x=600; values 1200,1800,3000; notes 24,18,6
            12 => [
                'difficulty' => 'hard',
                'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are such that their total values are in the ratio 2 : 3 : 5. If the total amount is ₹ 6000, find the number of notes of each denomination.',
                'answers' => [
                    'mode' => 'variable',
                    'check_equation' => true,
                    'table_schema' => $schema,
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

        foreach ($exercises as $sortOrder => $data) {
            $exists = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->exists();

            $payload = [
                'difficulty' => $data['difficulty'],
                'is_demo' => false,
                'question_text' => $data['question_text'],
                'answers' => json_encode($data['answers']),
                'updated_at' => $now,
            ];

            if ($exists) {
                DB::table('mmm_card_exercises')
                    ->where('mmm_card_id', $cardId)
                    ->where('sort_order', $sortOrder)
                    ->update($payload);
            } else {
                DB::table('mmm_card_exercises')->insert($payload + [
                    'mmm_card_id' => $cardId,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                ]);
            }
        }

        DB::table('mmm_cards')->where('id', $cardId)->update([
            'method_blurb' => 'For money word problems, make a table with denomination, amounts in x, and totals. For coin/note counts use an equation on total coins. When the ratio is of total values of notes, write values as parts of x, equate to total money, then find the number of notes = value ÷ denomination.',
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive.
    }
};
