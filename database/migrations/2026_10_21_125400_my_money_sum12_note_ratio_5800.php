<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sum 12: notes in ratio 2:3:5; 50(2x)+100(3x)+500(5x)=2900x=5800 so x is whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'my-money-table')->value('id');
        if (! $cardId) {
            return;
        }

        $row = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 12)
            ->first();
        if (! $row) {
            return;
        }

        $answers = json_decode($row->answers, true) ?: [];
        foreach (['rs50', 'rs100', 'rs500'] as $k) {
            if (isset($answers[$k]) && is_array($answers[$k])) {
                unset($answers[$k]['value_x']);
            }
        }

        $answers = array_merge($answers, [
            'mode' => 'variable',
            'check_equation' => true,
            'table_schema' => [
                'columns' => [
                    ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                    ['key' => 'count_x', 'label' => 'Notes (in x)'],
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
            ],
            'variable_tip' => 'The ratio is of the number of notes. Let notes be 2x, 3x and 5x. Total money: 50×2x + 100×3x + 500×5x = 5800, i.e. 100x + 300x + 2500x = 2900x = 5800. Find x, then fill No. of notes and Total value.',
            'equation_prompt' => 'Equation for total money',
            'equation_help' => 'Write 50×2x + 100×3x + 500×5x = 5800 (or 2900x = 5800).',
            'x_prompt' => 'Value of x',
            'equation' => [
                '50*2x+100*3x+500*5x=5800',
                '50(2x)+100(3x)+500(5x)=5800',
                '100x+300x+2500x=5800',
                '2900x=5800',
            ],
            'rs50' => ['denomination' => '50', 'count_x' => '2x', 'count' => '4', 'total' => '200'],
            'rs100' => ['denomination' => '100', 'count_x' => '3x', 'count' => '6', 'total' => '600'],
            'rs500' => ['denomination' => '500', 'count_x' => '5x', 'count' => '10', 'total' => '5000'],
            'grand_count' => '20',
            'grand_total' => '5800',
            'x_value' => '2',
        ]);

        DB::table('mmm_card_exercises')->where('id', $row->id)->update([
            'question_text' => 'Notes of ₹ 50, ₹ 100 and ₹ 500 are in the ratio 2 : 3 : 5. If the total amount is ₹ 5800, find the number of notes of each denomination.',
            'answers' => json_encode($answers),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }
};
