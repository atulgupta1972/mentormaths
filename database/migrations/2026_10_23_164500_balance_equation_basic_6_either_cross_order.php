<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-6')->value('id');
        if (! $cardId) {
            return;
        }

        $rows = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->whereIn('sort_order', [1, 2, 5])
            ->get();

        $flags = [
            1 => ['products' => [['lt', 'rb'], ['lb', 'rt']]],
            2 => ['cross' => [['k5', 'k1']]],
            5 => ['cross' => [['p2x', 'p2']]],
        ];

        foreach ($rows as $row) {
            $answers = json_decode($row->answers, true);
            $steps = $answers['play']['steps'] ?? null;
            if (! is_array($answers) || ! is_array($steps)) {
                continue;
            }
            $changed = false;
            foreach ($steps as $index => $step) {
                $key = (string) ($step['key'] ?? '');
                $pairs = $flags[(int) $row->sort_order][$key] ?? null;
                if ($pairs === null || ! empty($step['commute'])) {
                    continue;
                }
                $steps[$index]['commute'] = $pairs;
                $changed = true;
            }
            if (! $changed) {
                continue;
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
