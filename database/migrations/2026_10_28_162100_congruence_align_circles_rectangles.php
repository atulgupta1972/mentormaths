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
            'method_blurb' => 'First map the second triangle onto the main one: put matching corners in the round circles, and matching lengths in the side rectangles. Then mark the figure, write the correspondence table, and name the case (SAS, SSS, ASA, AAS, or RHS).',
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

            if ((int) $exercise->sort_order === 1) {
                $steps[$orderIndex]['title'] = 'Align';
                $steps[$orderIndex]['prompt'] = 'Put P, Q, R in the vertex circles so matching corners sit together. Then put 5 cm and 6 cm in the side rectangles that match those lengths.';
                $steps[$orderIndex]['length_chips'] = [
                    ['id' => '5', 'label' => '5 cm'],
                    ['id' => '6', 'label' => '6 cm'],
                ];
                $answers['check']['order']['sides'] = ['AB' => '5', 'AC' => '6'];
                $answers['check']['order']['ok'] = '△ABC ≅ △PQR. P sits on A, Q on B, R on C. 5 cm is on AB, 6 cm on AC.';
                $answers['check']['order']['miss'] = 'Circles: P on A, Q on B, R on C. Rectangles: 5 cm on AB, 6 cm on AC.';
            }

            if ((int) $exercise->sort_order === 2) {
                $steps[$orderIndex]['title'] = 'Align';
                $steps[$orderIndex]['prompt'] = 'Turn △PQR onto △ABC. Put the matching letters in the vertex circles, then drop 5 cm, 6 cm and 7 cm into the matching side rectangles.';
                $steps[$orderIndex]['length_chips'] = [
                    ['id' => '5', 'label' => '5 cm'],
                    ['id' => '6', 'label' => '6 cm'],
                    ['id' => '7', 'label' => '7 cm'],
                ];
                $answers['check']['order']['sides'] = ['AB' => '5', 'BC' => '6', 'AC' => '7'];
                $answers['check']['order']['ok'] = 'After the turn, △ABC ≅ △QPR. Q sits on A, P on B, R on C. 5 cm is on AB, 6 cm on BC, 7 cm on AC.';
                $answers['check']['order']['miss'] = 'Circles: Q on A, P on B, R on C. Rectangles: 5 cm on AB, 6 cm on BC, 7 cm on AC.';
            }

            $answers['play']['steps'] = $steps;

            DB::table('mmm_card_exercises')->where('id', $exercise->id)->update([
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Content-only update; leave the aligned prompts in place.
    }
};
