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
            'title' => 'Congruent triangles',
            'heading' => 'Congruent triangles',
            'method_blurb' => 'Turn the second triangle until the equal sides lie together, then mark them. Write each pair in the table with its reason. The table names the case: SAS when the angle is between the two sides, SSS when all three sides match.',
            'updated_at' => $now,
        ]);

        $first = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 1)
            ->first();
        if ($first) {
            $answers = json_decode($first->answers, true) ?: [];
            $answers['check']['summary'] = [
                'statement' => '△ABC ≅ △PQR',
                'case' => 'SAS',
                'because' => 'AB and PQ are equal sides, AC and PR are equal sides, and ∠BAC = ∠QPR sits between those sides. Two sides and the included angle prove the triangles congruent by SAS.',
                'rows' => [
                    ['parts' => 'AB = PQ = 5 cm', 'reason' => 'Given equal sides of 5 cm', 'type' => 'Side'],
                    ['parts' => 'AC = PR = 6 cm', 'reason' => 'Given equal sides of 6 cm', 'type' => 'Side'],
                    ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles', 'type' => 'Angle'],
                ],
            ];
            foreach ($answers['play']['figures'] ?? [] as $index => $figure) {
                if (($figure['id'] ?? '') === 'left') {
                    $answers['play']['figures'][$index]['lengths'] = ['AB' => '5 cm', 'AC' => '6 cm'];
                }
                if (($figure['id'] ?? '') === 'right') {
                    $answers['play']['figures'][$index]['lengths'] = ['PQ' => '5 cm', 'PR' => '6 cm'];
                }
            }
            DB::table('mmm_card_exercises')->where('id', $first->id)->update([
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }

        if (DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 2)->exists()) {
            return;
        }

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 2,
            'difficulty' => 'practice',
            'is_demo' => false,
            'question_text' => 'Turn △PQR until it sits on △ABC. AB = QP = 5 cm, BC = PR = 6 cm, and AC = RQ = 7 cm. Prove the triangles congruent.',
            'answers' => json_encode($this->sss(), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sss(): array
    {
        return [
            'mode' => 'congruence',
            'play' => [
                'givens' => ['AB = QP = 5 cm', 'BC = PR = 6 cm', 'AC = RQ = 7 cm'],
                'figures' => [
                    [
                        'id' => 'abc',
                        'name' => 'ABC',
                        'vertices' => [
                            ['id' => 'A', 'x' => 100, 'y' => 26],
                            ['id' => 'B', 'x' => 28, 'y' => 154],
                            ['id' => 'C', 'x' => 176, 'y' => 154],
                        ],
                        'sides' => ['AB', 'BC', 'AC'],
                        'lengths' => ['AB' => '5 cm', 'BC' => '6 cm', 'AC' => '7 cm'],
                    ],
                    [
                        'id' => 'pqr',
                        'name' => 'PQR',
                        'vertices' => [
                            ['id' => 'Q', 'x' => 34, 'y' => 78],
                            ['id' => 'P', 'x' => 168, 'y' => 36],
                            ['id' => 'R', 'x' => 154, 'y' => 158],
                        ],
                        'sides' => ['QP', 'PR', 'RQ'],
                        'lengths' => ['QP' => '5 cm', 'PR' => '6 cm', 'RQ' => '7 cm'],
                    ],
                ],
                'steps' => [
                    [
                        'key' => 'order',
                        'type' => 'order',
                        'title' => 'Turn',
                        'prompt' => 'Turn △PQR until the 5 cm side lies on AB, the 6 cm side lies on BC, and the 7 cm side lies on AC. Write the corners in that order.',
                        'left' => 'ABC',
                        'choices' => ['P', 'Q', 'R'],
                    ],
                    [
                        'key' => 'marks',
                        'type' => 'marks',
                        'title' => 'Mark',
                        'prompt' => 'Mark the equal sides. One tick for 5 cm, two ticks for 6 cm, three ticks for 7 cm.',
                        'tools' => [
                            ['id' => '5', 'label' => '5 cm', 'kind' => 'side'],
                            ['id' => '6', 'label' => '6 cm', 'kind' => 'side'],
                            ['id' => '7', 'label' => '7 cm', 'kind' => 'side'],
                        ],
                    ],
                    [
                        'key' => 'table',
                        'type' => 'table',
                        'title' => 'Table',
                        'prompt' => 'Write the 5 cm pair, then the 6 cm pair, then the 7 cm pair. Each reason is given, and every row is a side.',
                        'row_count' => 3,
                        'part_choices' => ['AB = QP', 'BC = PR', 'AC = RQ', 'AB = PQ', 'BC = QR', 'AC = PR'],
                        'reason_choices' => [
                            'Given equal sides of 5 cm',
                            'Given equal sides of 6 cm',
                            'Given equal sides of 7 cm',
                            'Vertically opposite angles',
                            'Common side',
                        ],
                        'type_choices' => ['Side', 'Angle'],
                    ],
                    [
                        'key' => 'case',
                        'type' => 'case',
                        'title' => 'Case',
                        'prompt' => 'Which case proves the triangles congruent?',
                        'choices' => ['SAS', 'SSS', 'ASA', 'AAS', 'RHS'],
                    ],
                ],
            ],
            'check' => [
                'order' => [
                    'letters' => ['Q', 'P', 'R'],
                    'ok' => 'After the turn, △ABC ≅ △QPR. Q matches A, P matches B, and R matches C.',
                    'miss' => 'The corner between the 5 cm side and the 7 cm side is A. On the other triangle that corner is Q, so the order is Q, then P, then R.',
                ],
                'marks' => [
                    'placed' => [
                        'AB' => '5',
                        'QP' => '5',
                        'BC' => '6',
                        'PR' => '6',
                        'AC' => '7',
                        'RQ' => '7',
                    ],
                    'ok' => 'The three pairs of equal sides are marked.',
                    'miss' => '5 cm goes on AB and QP. 6 cm goes on BC and PR. 7 cm goes on AC and RQ.',
                ],
                'table' => [
                    'rows' => [
                        ['parts' => 'AB = QP', 'reason' => 'Given equal sides of 5 cm', 'type' => 'Side'],
                        ['parts' => 'BC = PR', 'reason' => 'Given equal sides of 6 cm', 'type' => 'Side'],
                        ['parts' => 'AC = RQ', 'reason' => 'Given equal sides of 7 cm', 'type' => 'Side'],
                    ],
                    'ok' => 'Three pairs of sides are in the table.',
                    'miss' => 'Row 1 is AB = QP. Row 2 is BC = PR. Row 3 is AC = RQ. Each type is Side.',
                ],
                'case' => [
                    'value' => 'SSS',
                    'ok' => '△ABC ≅ △QPR by SSS. All three pairs of sides are equal.',
                    'miss' => 'The table has three sides and no angle. That case is SSS.',
                    'wrong' => [
                        'SAS' => 'No angle is in the table. Three equal sides is SSS.',
                        'ASA' => 'No angle was given. Three sides is SSS.',
                        'AAS' => 'No angle was given. Three sides is SSS.',
                        'RHS' => 'No right angle is marked. Three sides is SSS.',
                    ],
                ],
                'summary' => [
                    'statement' => '△ABC ≅ △QPR',
                    'case' => 'SSS',
                    'because' => 'Turning △PQR puts Q on A, P on B, and R on C. The three pairs of sides are equal, so the triangles are congruent by SSS.',
                    'rows' => [
                        ['parts' => 'AB = QP = 5 cm', 'reason' => 'Given equal sides of 5 cm', 'type' => 'Side'],
                        ['parts' => 'BC = PR = 6 cm', 'reason' => 'Given equal sides of 6 cm', 'type' => 'Side'],
                        ['parts' => 'AC = RQ = 7 cm', 'reason' => 'Given equal sides of 7 cm', 'type' => 'Side'],
                    ],
                ],
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'congruence-sas')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 2)
            ->delete();
    }
};
