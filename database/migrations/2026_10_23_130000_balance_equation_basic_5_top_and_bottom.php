<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-5')->value('id');
        if (! $cardId) {
            return;
        }

        $row = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 1)
            ->first();
        if (! $row) {
            return;
        }

        $answers = json_decode($row->answers, true);
        if (! is_array($answers)) {
            return;
        }

        $steps = $answers['play']['steps'] ?? null;
        if (! is_array($steps)) {
            return;
        }

        foreach ($steps as $step) {
            if (($step['key'] ?? '') !== 'denoms') {
                continue;
            }
            foreach ($step['columns'][0]['terms'] ?? [] as $term) {
                if (! empty($term['den_mul'])) {
                    return;
                }
            }
        }

        foreach ($steps as $index => $step) {
            if (($step['key'] ?? '') !== 'denoms') {
                continue;
            }
            $steps[$index]['hint'] = 'Multiply the top and the bottom by the same number. For (x − 2)/3 write 2 on the top and 2 on the bottom. For (2x − 3)/2 write 3 on the top and 3 on the bottom. The left denominator is 6. On the right, write 5 on the top and the bottom of 2/3, and 3 on the top and the bottom of 1/5. The right denominator is 15.';
            $steps[$index]['miss'] = '(x − 2)/3 needs 2 on the top and 2 on the bottom. (2x − 3)/2 needs 3 on the top and 3 on the bottom. Left denominator 6. Right: 5 and 5, then 3 and 3, denominator 15.';
            $steps[$index]['columns'] = [
                [
                    'title' => 'Left',
                    'terms' => [
                        [
                            'num' => '(x − 2)',
                            'den' => '3',
                            'box' => 'ml1',
                            'den_mul' => 'ml1d',
                            'label' => '2 on the top of (x − 2)',
                            'den_label' => '2 on the bottom of (x − 2)',
                        ],
                        [
                            'op' => '−',
                            'num' => '(2x − 3)',
                            'den' => '2',
                            'box' => 'ml2',
                            'den_mul' => 'ml2d',
                            'label' => '3 on the top of (2x − 3)',
                            'den_label' => '3 on the bottom of (2x − 3)',
                        ],
                    ],
                    'den_box' => 'denL',
                    'den_label' => 'Left denominator',
                    'top' => '2(x − 2) − 3(2x − 3)',
                ],
                [
                    'title' => 'Right',
                    'terms' => [
                        [
                            'num' => '2',
                            'den' => '3',
                            'box' => 'mr1',
                            'den_mul' => 'mr1d',
                            'label' => '5 on the top of 2/3',
                            'den_label' => '5 on the bottom of 2/3',
                        ],
                        [
                            'op' => '−',
                            'num' => '1',
                            'den' => '5',
                            'box' => 'mr2',
                            'den_mul' => 'mr2d',
                            'label' => '3 on the top of 1/5',
                            'den_label' => '3 on the bottom of 1/5',
                        ],
                    ],
                    'den_box' => 'denR',
                    'den_label' => 'Right denominator',
                    'top' => '5 × 2 − 3 × 1',
                ],
            ];
        }

        $answers['play']['steps'] = $steps;
        $answers['check']['ml1d'] = '2';
        $answers['check']['ml2d'] = '3';
        $answers['check']['mr1d'] = '5';
        $answers['check']['mr2d'] = '3';

        DB::table('mmm_card_exercises')
            ->where('id', $row->id)
            ->update([
                'answers' => json_encode($answers),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }
};
