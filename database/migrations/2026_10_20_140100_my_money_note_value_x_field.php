<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix sums 10–12 to use value_x column (Value in x), not count_x.
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

        $updates = [
            10 => [
                'rs50' => ['denomination' => '50', 'value_x' => '4x', 'count' => '40', 'total' => '2000'],
                'rs100' => ['denomination' => '100', 'value_x' => '5x', 'count' => '25', 'total' => '2500'],
                'rs500' => ['denomination' => '500', 'value_x' => '6x', 'count' => '6', 'total' => '3000'],
                'grand_count' => '71',
                'grand_total' => '7500',
                'x_value' => '500',
                'equation' => ['4x+5x+6x=7500', '15x=7500', '(4+5+6)x=7500'],
                'variable_tip' => 'The ratio is of total values (not of the number of notes). Let the values be 4x, 5x and 6x. Write the equation for total money, find x, fill Total value, then No. of notes = Total value ÷ denomination.',
            ],
            11 => [
                'rs50' => ['denomination' => '50', 'value_x' => '3x', 'count' => '30', 'total' => '1500'],
                'rs100' => ['denomination' => '100', 'value_x' => '4x', 'count' => '20', 'total' => '2000'],
                'rs500' => ['denomination' => '500', 'value_x' => '5x', 'count' => '5', 'total' => '2500'],
                'grand_count' => '55',
                'grand_total' => '6000',
                'x_value' => '500',
                'equation' => ['3x+4x+5x=6000', '12x=6000', '(3+4+5)x=6000'],
                'variable_tip' => 'Values are 3x, 4x and 5x. Equation for ₹ 6000 → find x → Total value of each → No. of notes = value ÷ 50, 100 or 500.',
            ],
            12 => [
                'rs50' => ['denomination' => '50', 'value_x' => '2x', 'count' => '24', 'total' => '1200'],
                'rs100' => ['denomination' => '100', 'value_x' => '3x', 'count' => '18', 'total' => '1800'],
                'rs500' => ['denomination' => '500', 'value_x' => '5x', 'count' => '6', 'total' => '3000'],
                'grand_count' => '48',
                'grand_total' => '6000',
                'x_value' => '600',
                'equation' => ['2x+3x+5x=6000', '10x=6000', '(2+3+5)x=6000'],
                'variable_tip' => 'Values are 2x, 3x and 5x. Make the equation with total money ₹ 6000, find x, then find the number of notes of each denomination.',
            ],
        ];

        foreach ($updates as $sortOrder => $extra) {
            $row = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->first();
            if (! $row) {
                continue;
            }
            $answers = json_decode($row->answers, true) ?: [];
            $answers = array_merge($answers, $extra, [
                'mode' => 'variable',
                'check_equation' => true,
                'table_schema' => $schema,
                'equation_prompt' => 'Equation for total money',
                'x_prompt' => 'Value of x',
            ]);
            // Drop old count_x keys if present on denomination rows.
            foreach (['rs50', 'rs100', 'rs500'] as $k) {
                if (isset($answers[$k]) && is_array($answers[$k])) {
                    unset($answers[$k]['count_x']);
                }
            }
            DB::table('mmm_card_exercises')->where('id', $row->id)->update([
                'answers' => json_encode($answers),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
