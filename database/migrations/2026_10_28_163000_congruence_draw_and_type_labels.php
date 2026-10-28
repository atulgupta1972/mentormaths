<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'congruence-sas')->value('id');
        if (! $cardId) {
            return;
        }

        $now = now();
        DB::table('mmm_cards')->where('id', $cardId)->update([
            'method_blurb' => 'Draw the first triangle as given. Beside it, draw the second triangle in the same shape and type P, Q or R at each corner. Matching sides then appear (AB = PQ, and so on). Mark, fill the table, and name the case.',
            'updated_at' => $now,
        ]);

        $exercises = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->orderBy('sort_order')->get();

        foreach ($exercises as $exercise) {
            $answers = json_decode($exercise->answers, true) ?: [];
            $steps = $answers['play']['steps'] ?? [];
            $orderIndex = collect($steps)->search(fn ($step) => ($step['key'] ?? '') === 'order');
            if ($orderIndex === false) {
                continue;
            }

            unset($steps[$orderIndex]['length_chips']);
            $steps[$orderIndex]['title'] = 'Draw';

            if ((int) $exercise->sort_order === 1) {
                $steps[$orderIndex]['prompt'] = 'Draw △ABC as given. Beside it, label the second triangle by typing P, Q or R at each corner. Then check whether AB matches PQ (and AC matches PR).';
                $answers['check']['order']['ok'] = '△ABC ≅ △PQR. You labelled P on A, Q on B, R on C, so AB = PQ and AC = PR.';
                $answers['check']['order']['miss'] = 'Type P at A, Q at B and R at C. Then AB = PQ and AC = PR.';
            }

            if ((int) $exercise->sort_order === 2) {
                $steps[$orderIndex]['prompt'] = 'Draw △ABC as given. Beside it, turn △PQR in your copy and type Q, P or R at each corner so equal sides sit together.';
                $answers['check']['order']['ok'] = '△ABC ≅ △QPR. Q sits with A, P with B, R with C, so AB = QP, BC = PR, AC = RQ.';
                $answers['check']['order']['miss'] = 'Type Q at A, P at B and R at C. Then AB = QP, BC = PR and AC = RQ.';
            }

            unset($answers['check']['order']['sides']);
            $answers['play']['steps'] = array_values($steps);

            DB::table('mmm_card_exercises')->where('id', $exercise->id)->update([
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Content-only update.
    }
};
