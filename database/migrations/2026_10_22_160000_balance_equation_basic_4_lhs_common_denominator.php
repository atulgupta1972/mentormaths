<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-4')->value('id');
        if (! $cardId) {
            return;
        }

        $row = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 2)
            ->first();
        if (! $row) {
            return;
        }

        $answers = json_decode($row->answers, true);
        if (! is_array($answers)) {
            return;
        }

        $steps = $answers['play']['steps'] ?? null;
        if (! is_array($steps) || ! isset($steps[0]['branches']['lhs']) || ! is_array($steps[0]['branches']['lhs'])) {
            return;
        }

        foreach ($steps[0]['branches']['lhs'] as $index => $step) {
            if (! is_array($step)) {
                continue;
            }
            if (($step['key'] ?? '') === 'frac_l') {
                $steps[0]['branches']['lhs'][$index] = [
                    'key' => 'frac_l',
                    'type' => 'common_den',
                    'keep_num' => '2x',
                    'keep_den' => '3',
                    'from_num' => '3x',
                    'from_den' => '1',
                    'right_a' => '−50 / 7',
                    'right_b' => '40 / 7',
                    'right_op' => '−',
                    'mul_key' => 'mul_l',
                    'top_key' => 'rew_l',
                    'sign_left' => '3x moved to the left, so the sign is minus.',
                    'sign_right' => '+40/7 moved to the right, so the sign is minus.',
                    'hint' => 'The equation is now 2x/3 − 3x = −50/7 − 40/7. Make the left denominators the same. Write only the number in each box.',
                    'miss' => '3x is 3x/1. Multiply top and bottom by 3. 3 × 3 = 9, so the new top is 9x.',
                ];
            }
            if (($step['key'] ?? '') === 'nums_l') {
                $steps[0]['branches']['lhs'][$index]['now'] = 'Left is 2x/3 − 9x/3. 2x − 9x = −7x, so the left side is −7x/3.';
            }
        }

        $answers['play']['steps'] = $steps;
        $answers['check']['mul_l'] = '3';
        $answers['check']['rew_l'] = '9';
        unset($answers['check']['joined_l']);

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
