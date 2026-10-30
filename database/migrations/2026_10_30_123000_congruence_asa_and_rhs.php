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
            'method_blurb' => 'Sketch the given triangle. Write the letters and lengths on the second triangle, and colour matching angles. Fill the condition table and name the case (SAS, SSS, ASA, AAS, or RHS). The model answer shows how to write it in your copy.',
            'updated_at' => $now,
        ]);

        if (! DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 3)->exists()) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => 3,
                'difficulty' => 'practice',
                'is_demo' => false,
                'question_text' => 'Prove △ABC ≅ △PQR. Given: ∠BAC = ∠QPR, AB = PQ = 5 cm, and ∠ABC = ∠PQR.',
                'answers' => json_encode($this->asa(), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 4)->exists()) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => 4,
                'difficulty' => 'practice',
                'is_demo' => false,
                'question_text' => 'Turn △PQR until the right angles sit together. ∠C = ∠Q = 90°, hypotenuse AB = PR = 5 cm, and AC = PQ = 4 cm. Prove the triangles congruent.',
                'answers' => json_encode($this->rhs(), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function asa(): array
    {
        return [
            'mode' => 'congruence',
            'play' => [
                'givens' => ['∠BAC = ∠QPR', 'AB = PQ = 5 cm', '∠ABC = ∠PQR'],
                'figures' => [
                    [
                        'id' => 'abc',
                        'name' => 'ABC',
                        'vertices' => [
                            ['id' => 'A', 'x' => 96, 'y' => 28],
                            ['id' => 'B', 'x' => 28, 'y' => 150],
                            ['id' => 'C', 'x' => 158, 'y' => 150],
                        ],
                        'sides' => ['AB', 'BC', 'AC'],
                        'lengths' => ['AB' => '5 cm'],
                        'shaded_angles' => ['A', 'B'],
                    ],
                    [
                        'id' => 'pqr',
                        'name' => 'PQR',
                        'rotated' => true,
                        'rotated_order' => ['P', 'Q', 'R'],
                        'vertices' => [
                            ['id' => 'P', 'x' => 100, 'y' => 24],
                            ['id' => 'Q', 'x' => 22, 'y' => 152],
                            ['id' => 'R', 'x' => 176, 'y' => 140],
                        ],
                        'sides' => ['PQ', 'QR', 'PR'],
                        'lengths' => ['PQ' => '5 cm'],
                    ],
                ],
                'steps' => [
                    [
                        'key' => 'order',
                        'type' => 'order',
                        'title' => 'Sketch',
                        'prompt' => 'Sketch △ABC as given. Write P, Q, R and the 5 cm side. Click ∠A on △ABC then the matching corner on the second triangle. Next click ∠B (green) and its match.',
                        'left' => 'ABC',
                        'choices' => ['P', 'Q', 'R'],
                        'shade_angle' => true,
                    ],
                    [
                        'key' => 'table',
                        'type' => 'table',
                        'title' => 'Table',
                        'prompt' => 'Fill the condition table, then name the case.',
                        'row_count' => 3,
                        'part_choices' => ['∠BAC = ∠QPR', 'AB = PQ', '∠ABC = ∠PQR', 'AC = PR', 'BC = QR', '∠ACB = ∠PRQ'],
                        'reason_choices' => ['Given equal angles', 'Given = 5 cm', 'Given = 6 cm', 'Vertically opposite angles', 'Common side'],
                        'case_choices' => ['SAS', 'SSS', 'ASA', 'AAS', 'RHS'],
                    ],
                ],
            ],
            'check' => [
                'order' => [
                    'letters' => ['P', 'Q', 'R'],
                    'angle_pairs' => ['A' => 'P', 'B' => 'Q'],
                    'sides' => ['AB' => '5'],
                    'ok' => 'P on A, Q on B, R on C. 5 cm on AB. ∠A matches ∠P and ∠B matches ∠Q.',
                    'miss' => 'Write P, Q, R. Put 5 on AB. Colour-match A with P, then B with Q (green).',
                ],
                'table' => [
                    'rows' => [
                        ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles'],
                        ['parts' => 'AB = PQ', 'reason' => 'Given = 5 cm'],
                        ['parts' => '∠ABC = ∠PQR', 'reason' => 'Given equal angles'],
                    ],
                    'ok' => 'The condition table is right.',
                    'miss' => 'Row 1: ∠BAC = ∠QPR. Row 2: AB = PQ, given = 5 cm. Row 3: ∠ABC = ∠PQR. The side sits between the two angles.',
                ],
                'case' => [
                    'value' => 'ASA',
                    'ok' => '△ABC ≅ △PQR by ASA. The 5 cm side sits between the two equal angles.',
                    'miss' => 'Two angles and the included side. That case is ASA.',
                    'wrong' => [
                        'SAS' => 'The side sits between the two angles, so the case is ASA, not SAS.',
                        'AAS' => 'The side is between the two angles (included). That is ASA.',
                        'SSS' => 'Two angles are given, so it is not three sides.',
                        'RHS' => 'No right angle is given. Two angles and the included side is ASA.',
                    ],
                ],
                'summary' => [
                    'statement' => '△ABC ≅ △PQR',
                    'case' => 'ASA',
                    'because' => '∠BAC = ∠QPR, AB = PQ sits between those angles, and ∠ABC = ∠PQR. Two angles and the included side prove the triangles congruent by ASA.',
                    'copy_order' => ['P', 'Q', 'R'],
                    'ticks' => ['AB' => 1, 'PQ' => 1],
                    'angles' => ['A', 'P', 'B', 'Q'],
                    'advice' => 'In your copy, cut the 5 cm sides with /. Shade ∠A and ∠P the same colour, and ∠B and ∠Q green. This is how the sum is written.',
                    'rows' => [
                        ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles'],
                        ['parts' => 'AB = PQ = 5 cm', 'reason' => 'Given = 5 cm'],
                        ['parts' => '∠ABC = ∠PQR', 'reason' => 'Given equal angles'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rhs(): array
    {
        return [
            'mode' => 'congruence',
            'play' => [
                'givens' => ['∠C = ∠Q = 90°', 'hypotenuse AB = PR = 5 cm', 'AC = PQ = 4 cm'],
                'figures' => [
                    [
                        'id' => 'abc',
                        'name' => 'ABC',
                        'vertices' => [
                            ['id' => 'A', 'x' => 168, 'y' => 28],
                            ['id' => 'B', 'x' => 32, 'y' => 150],
                            ['id' => 'C', 'x' => 168, 'y' => 150],
                        ],
                        'sides' => ['AB', 'BC', 'AC'],
                        'lengths' => ['AB' => '5 cm', 'AC' => '4 cm'],
                        'right_angles' => ['C'],
                    ],
                    [
                        'id' => 'pqr',
                        'name' => 'PQR',
                        'rotated' => true,
                        'rotated_order' => ['P', 'R', 'Q'],
                        'vertices' => [
                            ['id' => 'Q', 'x' => 36, 'y' => 36],
                            ['id' => 'P', 'x' => 36, 'y' => 150],
                            ['id' => 'R', 'x' => 160, 'y' => 36],
                        ],
                        'sides' => ['PQ', 'QR', 'PR'],
                        'lengths' => ['PR' => '5 cm', 'PQ' => '4 cm'],
                        'right_angles' => ['Q'],
                    ],
                ],
                'steps' => [
                    [
                        'key' => 'order',
                        'type' => 'order',
                        'title' => 'Sketch',
                        'prompt' => 'Sketch △ABC as given (right-angled at C). Turn the second triangle so the right angles sit together. Write P, R, Q, the two lengths, and click the right angle on △ABC then the matching corner.',
                        'left' => 'ABC',
                        'choices' => ['P', 'Q', 'R'],
                        'shade_angle' => true,
                    ],
                    [
                        'key' => 'table',
                        'type' => 'table',
                        'title' => 'Table',
                        'prompt' => 'Fill the condition table, then name the case.',
                        'row_count' => 3,
                        'part_choices' => ['∠C = ∠Q = 90°', 'AB = PR', 'AC = PQ', 'AB = PQ', 'BC = QR', '∠A = ∠P'],
                        'reason_choices' => ['Given right angles', 'Given hypotenuse = 5 cm', 'Given = 4 cm', 'Given = 5 cm', 'Vertically opposite angles'],
                        'case_choices' => ['SAS', 'SSS', 'ASA', 'AAS', 'RHS'],
                    ],
                ],
            ],
            'check' => [
                'order' => [
                    'letters' => ['P', 'R', 'Q'],
                    'angle_pairs' => ['C' => 'Q'],
                    'sides' => ['AB' => '5', 'AC' => '4'],
                    'ok' => 'P on A, R on B, Q on C. 5 cm on the hypotenuse AB, 4 cm on AC, and the right angles match.',
                    'miss' => 'Write P at A, R at B and Q at C. Put 5 on AB, 4 on AC, and colour-match the right angle at C with Q.',
                ],
                'table' => [
                    'rows' => [
                        ['parts' => '∠C = ∠Q = 90°', 'reason' => 'Given right angles'],
                        ['parts' => 'AB = PR', 'reason' => 'Given hypotenuse = 5 cm'],
                        ['parts' => 'AC = PQ', 'reason' => 'Given = 4 cm'],
                    ],
                    'ok' => 'The condition table is right.',
                    'miss' => 'Row 1: the right angles. Row 2: hypotenuse AB = PR. Row 3: AC = PQ.',
                ],
                'case' => [
                    'value' => 'RHS',
                    'ok' => '△ABC ≅ △PRQ by RHS. Right angle, hypotenuse, and one more side.',
                    'miss' => 'A right angle, the hypotenuse, and one side. That case is RHS.',
                    'wrong' => [
                        'SAS' => 'The given angle is a right angle, and one given side is the hypotenuse. That case is RHS.',
                        'ASA' => 'A right angle, hypotenuse and a side is RHS, not ASA.',
                        'AAS' => 'The right angle and hypotenuse make RHS, not AAS.',
                        'SSS' => 'A right angle is given, so it is not three sides. The case is RHS.',
                    ],
                ],
                'summary' => [
                    'statement' => '△ABC ≅ △PRQ',
                    'case' => 'RHS',
                    'because' => 'Turning △PQR puts P on A, R on B, and Q on C. Both triangles have a right angle, equal hypotenuses, and one more equal side, so they are congruent by RHS.',
                    'copy_order' => ['P', 'R', 'Q'],
                    'ticks' => ['AB' => 1, 'PR' => 1, 'AC' => 2, 'PQ' => 2],
                    'angles' => [],
                    'right_angles' => ['C', 'Q'],
                    'advice' => 'In your copy, mark the right angles with a square. Cut the hypotenuses with / and the 4 cm sides with //. This is how the sum is written.',
                    'rows' => [
                        ['parts' => '∠C = ∠Q = 90°', 'reason' => 'Given right angles'],
                        ['parts' => 'AB = PR = 5 cm (hypotenuse)', 'reason' => 'Given hypotenuse = 5 cm'],
                        ['parts' => 'AC = PQ = 4 cm', 'reason' => 'Given = 4 cm'],
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
            ->whereIn('sort_order', [3, 4])
            ->delete();
    }
};
