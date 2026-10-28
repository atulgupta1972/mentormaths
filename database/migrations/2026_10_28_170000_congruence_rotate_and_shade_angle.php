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
            'method_blurb' => 'The question first turns the second triangle so it sits on △ABC. Draw △ABC as given — the equal angle is already shaded. Copy that pose, type the matching letters, and shade the same given angle. Then mark, fill the table, and name the case.',
            'updated_at' => $now,
        ]);

        $exercises = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->orderBy('sort_order')->get();

        foreach ($exercises as $exercise) {
            $answers = json_decode($exercise->answers, true) ?: [];
            $figures = $answers['play']['figures'] ?? [];
            $steps = $answers['play']['steps'] ?? [];
            $orderIndex = collect($steps)->search(fn ($step) => ($step['key'] ?? '') === 'order');

            foreach ($figures as $index => $figure) {
                $id = (string) ($figure['id'] ?? '');
                $name = (string) ($figure['name'] ?? '');
                $isHome = in_array($id, ['left', 'abc'], true) || $name === 'ABC';
                $isCopy = in_array($id, ['right', 'pqr'], true) || in_array($name, ['PQR', 'QPR'], true);

                if ($isHome && (int) $exercise->sort_order === 1) {
                    $figures[$index]['shaded_angles'] = ['A'];
                }

                if ($isCopy) {
                    $figures[$index]['rotated'] = true;
                    if ((int) $exercise->sort_order === 1) {
                        $figures[$index]['rotated_order'] = ['P', 'Q', 'R'];
                    }
                    if ((int) $exercise->sort_order === 2) {
                        $figures[$index]['rotated_order'] = ['Q', 'P', 'R'];
                    }
                }
            }

            if ($orderIndex !== false) {
                $steps[$orderIndex]['title'] = 'Turn';

                if ((int) $exercise->sort_order === 1) {
                    $steps[$orderIndex]['shade_angle'] = true;
                    $steps[$orderIndex]['prompt'] = 'The question already shows △PQR turned onto △ABC. Draw △ABC as given — the equal angle is shaded. Type P, Q or R on the second triangle, then tap the matching corner to shade the same given angle.';
                    $answers['check']['order']['angle'] = 'A';
                    $answers['check']['order']['ok'] = '△ABC ≅ △PQR. You labelled P on A, Q on B, R on C, and shaded the equal given angle at A (matching ∠P).';
                    $answers['check']['order']['miss'] = 'Type P at A, Q at B and R at C. Shade the equal given angle at the top corner (A / P).';
                }

                if ((int) $exercise->sort_order === 2) {
                    unset($steps[$orderIndex]['shade_angle']);
                    $steps[$orderIndex]['prompt'] = 'The question already shows the second triangle turned as △QPR. Draw △ABC as given. Type Q, P or R at each corner so equal sides sit together.';
                    unset($answers['check']['order']['angle']);
                    $answers['check']['order']['ok'] = '△ABC ≅ △QPR. Q sits with A, P with B, R with C, so AB = QP, BC = PR, AC = RQ.';
                    $answers['check']['order']['miss'] = 'Type Q at A, P at B and R at C. Then AB = QP, BC = PR and AC = RQ.';
                }
            }

            $answers['play']['figures'] = $figures;
            if ($orderIndex !== false) {
                $answers['play']['steps'] = array_values($steps);
            }

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
