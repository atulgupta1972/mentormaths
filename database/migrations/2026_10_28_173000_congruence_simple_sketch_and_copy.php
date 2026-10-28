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
            'method_blurb' => 'Sketch the given triangle. On the second triangle write the letters, the side lengths, and shade the equal angle. Fill the condition table and name the case. The model answer then shows how to cut equal sides with / and // in your copy.',
            'updated_at' => $now,
        ]);

        $exercises = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->orderBy('sort_order')->get();

        foreach ($exercises as $exercise) {
            $answers = json_decode($exercise->answers, true) ?: [];
            $steps = array_values(array_filter(
                $answers['play']['steps'] ?? [],
                fn ($step) => ! in_array($step['key'] ?? '', ['marks', 'case'], true)
            ));
            $orderIndex = collect($steps)->search(fn ($step) => ($step['key'] ?? '') === 'order');
            $tableIndex = collect($steps)->search(fn ($step) => ($step['key'] ?? '') === 'table');

            if ($orderIndex !== false) {
                $steps[$orderIndex]['title'] = 'Sketch';
                unset($steps[$orderIndex]['length_chips']);
            }

            if ($tableIndex !== false) {
                $steps[$tableIndex]['title'] = 'Table';
                $steps[$tableIndex]['prompt'] = 'Fill the condition table, then name the case.';
                $steps[$tableIndex]['case_choices'] = ['SAS', 'SSS', 'ASA', 'AAS', 'RHS'];
                unset($steps[$tableIndex]['type_choices']);
            }

            if ((int) $exercise->sort_order === 1) {
                if ($orderIndex !== false) {
                    $steps[$orderIndex]['prompt'] = 'Sketch △ABC as given. On the second triangle write P, Q, R, the side lengths, and shade the equal angle.';
                    $steps[$orderIndex]['shade_angle'] = true;
                }
                if ($tableIndex !== false) {
                    $steps[$tableIndex]['row_count'] = 3;
                    $steps[$tableIndex]['part_choices'] = ['AB = PQ', 'AC = PR', 'BC = QR', '∠BAC = ∠QPR', '∠ABC = ∠PQR', '∠ACB = ∠PRQ'];
                    $steps[$tableIndex]['reason_choices'] = ['Given = 5 cm', 'Given = 6 cm', 'Given equal angles', 'Vertically opposite angles', 'Common side'];
                }
                $answers['check']['order'] = [
                    'letters' => ['P', 'Q', 'R'],
                    'angle' => 'A',
                    'sides' => ['AB' => '5', 'AC' => '6'],
                    'ok' => 'P on A, Q on B, R on C. 5 cm on AB, 6 cm on AC, and the equal angle is shaded.',
                    'miss' => 'Write P, Q, R in that order. Put 5 on the side matching AB, 6 on the side matching AC, and shade the top angle.',
                ];
                $answers['check']['table'] = [
                    'rows' => [
                        ['parts' => 'AB = PQ', 'reason' => 'Given = 5 cm'],
                        ['parts' => 'AC = PR', 'reason' => 'Given = 6 cm'],
                        ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles'],
                    ],
                    'ok' => 'The condition table is right.',
                    'miss' => 'Row 1: AB = PQ, given = 5 cm. Row 2: AC = PR, given = 6 cm. Row 3: the equal angles.',
                ];
                $answers['check']['summary'] = array_merge($answers['check']['summary'] ?? [], [
                    'copy_order' => ['P', 'Q', 'R'],
                    'ticks' => ['AB' => 1, 'PQ' => 1, 'AC' => 2, 'PR' => 2],
                    'angles' => ['A', 'P'],
                    'advice' => 'In your copy, cut the 5 cm sides with / and the 6 cm sides with //. Shade the equal angles. This is how the sum is written.',
                    'rows' => [
                        ['parts' => 'AB = PQ = 5 cm', 'reason' => 'Given = 5 cm'],
                        ['parts' => 'AC = PR = 6 cm', 'reason' => 'Given = 6 cm'],
                        ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles'],
                    ],
                ]);
            }

            if ((int) $exercise->sort_order === 2) {
                if ($orderIndex !== false) {
                    $steps[$orderIndex]['prompt'] = 'Sketch △ABC as given. On the second triangle write Q, P, R and the three side lengths so equal sides sit together.';
                    unset($steps[$orderIndex]['shade_angle']);
                }
                if ($tableIndex !== false) {
                    $steps[$tableIndex]['row_count'] = 3;
                    $steps[$tableIndex]['part_choices'] = ['AB = QP', 'BC = PR', 'AC = RQ', 'AB = PQ', 'BC = QR', 'AC = PR'];
                    $steps[$tableIndex]['reason_choices'] = ['Given = 5 cm', 'Given = 6 cm', 'Given = 7 cm', 'Vertically opposite angles', 'Common side'];
                }
                $answers['check']['order'] = [
                    'letters' => ['Q', 'P', 'R'],
                    'sides' => ['AB' => '5', 'BC' => '6', 'AC' => '7'],
                    'ok' => 'Q on A, P on B, R on C. 5 cm on AB, 6 cm on BC, 7 cm on AC.',
                    'miss' => 'Write Q, P, R in that order. Put 5 on AB, 6 on BC and 7 on AC.',
                ];
                unset($answers['check']['order']['angle']);
                $answers['check']['table'] = [
                    'rows' => [
                        ['parts' => 'AB = QP', 'reason' => 'Given = 5 cm'],
                        ['parts' => 'BC = PR', 'reason' => 'Given = 6 cm'],
                        ['parts' => 'AC = RQ', 'reason' => 'Given = 7 cm'],
                    ],
                    'ok' => 'The condition table is right.',
                    'miss' => 'Row 1: AB = QP, given = 5 cm. Row 2: BC = PR, given = 6 cm. Row 3: AC = RQ, given = 7 cm.',
                ];
                $answers['check']['summary'] = array_merge($answers['check']['summary'] ?? [], [
                    'copy_order' => ['Q', 'P', 'R'],
                    'ticks' => ['AB' => 1, 'QP' => 1, 'BC' => 2, 'PR' => 2, 'AC' => 3, 'RQ' => 3],
                    'angles' => [],
                    'advice' => 'In your copy, cut the 5 cm sides with /, the 6 cm sides with //, and the 7 cm sides with ///. This is how the sum is written.',
                    'rows' => [
                        ['parts' => 'AB = QP = 5 cm', 'reason' => 'Given = 5 cm'],
                        ['parts' => 'BC = PR = 6 cm', 'reason' => 'Given = 6 cm'],
                        ['parts' => 'AC = RQ = 7 cm', 'reason' => 'Given = 7 cm'],
                    ],
                ]);
            }

            unset($answers['check']['marks']);
            $answers['play']['steps'] = $steps;

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
