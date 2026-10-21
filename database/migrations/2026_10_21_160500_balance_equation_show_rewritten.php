<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Before x = 25 + [ ], show the tidied equation x − 5 = 25. */
return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-the-equation')->value('id');
        if (! $cardId) {
            return;
        }

        $byOrder = [
            10 => [
                'rewrite_left' => 'x − 10',
                'hint' => 'Now the equation is x − 10 = 80. Add the opposite of −10 (write 10).',
                'frozen_from' => 'rhs',
            ],
            11 => [
                'rewrite_left' => 'x + 6',
                'hint' => 'Now the equation is x + 6 = 11. Add the opposite of +6 (write −6).',
                'frozen_from' => 'rhs',
            ],
            12 => [
                'rewrite_left' => 'x − 5',
                'hint' => 'Now the equation is x − 5 = 25. Add the opposite of −5 (write 5).',
                'frozen_from' => 'lhs',
            ],
        ];

        foreach ($byOrder as $sortOrder => $meta) {
            $row = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->first();
            if (! $row) {
                continue;
            }
            $answers = json_decode($row->answers, true) ?: [];
            $steps = $answers['play']['steps'] ?? [];
            foreach ($steps as $i => $step) {
                if (($step['type'] ?? '') !== 'move') {
                    continue;
                }
                $steps[$i]['rewrite_left'] = $meta['rewrite_left'];
                $steps[$i]['hint'] = $meta['hint'];
                $steps[$i]['frozen_from'] = $meta['frozen_from'];
            }
            $answers['play']['steps'] = $steps;
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
