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

        $changed = false;
        foreach ($steps as $index => $step) {
            if (($step['key'] ?? '') === 'denoms') {
                $steps[$index]['done'] = 'Top and bottom multiplied. Left denominator 6, right denominator 15.';
                $changed = true;
            }
            if (($step['key'] ?? '') === 'open' && empty($step['action'])) {
                unset($steps[$index]['from']);
                $steps[$index]['action'] = [
                    [
                        'title' => 'Left',
                        'terms' => [
                            ['factor' => '2', 'num' => '(x − 2)', 'den' => '3'],
                            ['op' => '−', 'factor' => '3', 'num' => '(2x − 3)', 'den' => '2'],
                        ],
                    ],
                    [
                        'title' => 'Right',
                        'terms' => [
                            ['factor' => '5', 'num' => '2', 'den' => '3'],
                            ['op' => '−', 'factor' => '3', 'num' => '1', 'den' => '5'],
                        ],
                    ],
                ];
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $answers['play']['steps'] = $steps;
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
