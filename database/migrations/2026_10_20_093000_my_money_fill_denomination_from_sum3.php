<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sums 3+ on My Money: student fills Denomination (in ₹); wrong values are checked.
 */
return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'my-money-table')->value('id');
        if (! $cardId) {
            return;
        }

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

        $ratioSchema2510 = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'ratio', 'label' => 'Ratio of coins'],
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

        $ratioSchema2050100 = [
            'columns' => [
                ['key' => 'denomination', 'label' => 'Denomination (in ₹)'],
                ['key' => 'ratio', 'label' => 'Ratio of coins'],
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

        $updates = [
            3 => [
                'table_schema' => $fillDenom2510,
                'rs2' => ['denomination' => '2', 'count' => '8', 'total' => '16'],
                'rs5' => ['denomination' => '5', 'count' => '2', 'total' => '10'],
                'rs10' => ['denomination' => '10', 'count' => '4', 'total' => '40'],
                'grand_total' => '66',
            ],
            4 => [
                'table_schema' => $fillDenom2510,
                'rs2' => ['denomination' => '2', 'count' => '10', 'total' => '20'],
                'rs5' => ['denomination' => '5', 'count' => '7', 'total' => '35'],
                'rs10' => ['denomination' => '10', 'count' => '5', 'total' => '50'],
                'grand_total' => '105',
            ],
            5 => [
                'table_schema' => $fillDenom2050100,
                'rs20' => ['denomination' => '20', 'count' => '3', 'total' => '60'],
                'rs50' => ['denomination' => '50', 'count' => '2', 'total' => '100'],
                'rs100' => ['denomination' => '100', 'count' => '1', 'total' => '100'],
                'grand_total' => '260',
            ],
            6 => [
                'table_schema' => $ratioSchema2510,
                'method_tip' => 'Write each denomination (2, 5, 10) and the ratio parts. Total parts = 2 + 3 + 4 = 9. Each part = 27 ÷ 9 = 3 coins. Then fill No. of coins and Total value.',
                'rs2' => ['denomination' => '2', 'ratio' => '2', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'ratio' => '3', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'ratio' => '4', 'count' => '12', 'total' => '120'],
                'grand_count' => '27',
                'grand_total' => '177',
            ],
            7 => [
                'table_schema' => $ratioSchema2510,
                'method_tip' => 'Write denominations 2, 5, 10. Ratio parts 2 : 3 : 4 → 9 parts. One part = 36 ÷ 9 = 4. So counts are 8, 12 and 16.',
                'rs2' => ['denomination' => '2', 'ratio' => '2', 'count' => '8', 'total' => '16'],
                'rs5' => ['denomination' => '5', 'ratio' => '3', 'count' => '12', 'total' => '60'],
                'rs10' => ['denomination' => '10', 'ratio' => '4', 'count' => '16', 'total' => '160'],
                'grand_count' => '36',
                'grand_total' => '236',
            ],
            8 => [
                'table_schema' => $ratioSchema2510,
                'method_tip' => 'Write denominations 2, 5, 10. 9 equal parts make 45 coins → each part is 5 coins.',
                'rs2' => ['denomination' => '2', 'ratio' => '2', 'count' => '10', 'total' => '20'],
                'rs5' => ['denomination' => '5', 'ratio' => '3', 'count' => '15', 'total' => '75'],
                'rs10' => ['denomination' => '10', 'ratio' => '4', 'count' => '20', 'total' => '200'],
                'grand_count' => '45',
                'grand_total' => '295',
            ],
            9 => [
                'table_schema' => $ratioSchema2050100,
                'method_tip' => 'Write denominations 20, 50, 100. Same ratio idea: 2 + 3 + 4 = 9 parts. One part = 27 ÷ 9 = 3 notes.',
                'rs20' => ['denomination' => '20', 'ratio' => '2', 'count' => '6', 'total' => '120'],
                'rs50' => ['denomination' => '50', 'ratio' => '3', 'count' => '9', 'total' => '450'],
                'rs100' => ['denomination' => '100', 'ratio' => '4', 'count' => '12', 'total' => '1200'],
                'grand_count' => '27',
                'grand_total' => '1770',
            ],
            10 => [
                'table_schema' => $ratioSchema2050100,
                'method_tip' => 'Write denominations 20, 50, 100. 9 parts → 45 notes → each part = 5 notes. Counts: 10, 15 and 20.',
                'rs20' => ['denomination' => '20', 'ratio' => '2', 'count' => '10', 'total' => '200'],
                'rs50' => ['denomination' => '50', 'ratio' => '3', 'count' => '15', 'total' => '750'],
                'rs100' => ['denomination' => '100', 'ratio' => '4', 'count' => '20', 'total' => '2000'],
                'grand_count' => '45',
                'grand_total' => '2950',
            ],
        ];

        foreach ($updates as $sortOrder => $answers) {
            DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->update([
                    'answers' => json_encode($answers),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Non-destructive; left intentionally empty.
    }
};
