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
        $steps = $answers['play']['steps'] ?? null;
        if (! is_array($steps)) {
            return;
        }

        $original = [
            ['terms' => [
                ['num' => '(x − 2)', 'den' => '3'],
                ['op' => '−', 'num' => '(2x − 3)', 'den' => '2'],
            ]],
            ['terms' => [
                ['num' => '2', 'den' => '3'],
                ['op' => '−', 'num' => '1', 'den' => '5'],
            ]],
        ];
        $multiplied = [
            ['terms' => [
                ['factor' => '2', 'num' => '(x − 2)', 'den' => '3'],
                ['op' => '−', 'factor' => '3', 'num' => '(2x − 3)', 'den' => '2'],
            ]],
            ['terms' => [
                ['factor' => '5', 'num' => '2', 'den' => '3'],
                ['op' => '−', 'factor' => '3', 'num' => '1', 'den' => '5'],
            ]],
        ];
        $opened = [
            ['terms' => [['num' => '2x − 4 − 6x + 9', 'den' => '6']]],
            ['terms' => [['num' => '7', 'den' => '15']]],
        ];
        $combined = [
            ['terms' => [['num' => '−4x + 5', 'den' => '6']]],
            ['terms' => [['num' => '7', 'den' => '15']]],
        ];
        $boards = [
            'brackets' => $original,
            'denoms' => $multiplied,
            'open' => $opened,
            'combine' => $combined,
        ];

        $changed = false;
        foreach ($steps as $index => $step) {
            $key = (string) ($step['key'] ?? '');
            if (isset($boards[$key]) && empty($step['done_board'])) {
                $steps[$index]['done_board'] = $boards[$key];
                unset($steps[$index]['done']);
                $changed = true;
            }
            if ($key === 'combine' && empty($step['action'])) {
                $steps[$index]['action_label'] = 'Finish the numerator';
                $steps[$index]['action'] = [
                    ['title' => 'Left', 'terms' => [['num' => '2x − 4 − 6x + 9', 'den' => '6']]],
                    ['title' => 'Right', 'terms' => [['num' => '7', 'den' => '15']]],
                ];
                unset($steps[$index]['from']);
                $changed = true;
            }
            if ($key === 'cross' && empty($step['action'])) {
                $steps[$index]['action_label'] = 'Cross';
                $steps[$index]['action'] = [
                    ['title' => 'Left', 'terms' => [['num' => '−4x + 5', 'den' => '6']]],
                    ['title' => 'Right', 'terms' => [['num' => '7', 'den' => '15']]],
                ];
                unset($steps[$index]['from']);
                $changed = true;
            }
        }

        if (! $changed) {
            return;
        }

        $answers['play']['steps'] = $steps;
        DB::table('mmm_card_exercises')->where('id', $row->id)->update([
            'answers' => json_encode($answers),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }
};
