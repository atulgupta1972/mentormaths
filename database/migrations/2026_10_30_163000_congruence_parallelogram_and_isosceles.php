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

        if (! DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 5)->exists()) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => 5,
                'difficulty' => 'practice',
                'is_demo' => false,
                'question_text' => 'ABCD is a parallelogram. Prove △ABC ≅ △CDA.',
                'answers' => json_encode($this->parallelogram(), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 6)->exists()) {
            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => 6,
                'difficulty' => 'practice',
                'is_demo' => false,
                'question_text' => '△ABC is isosceles with AB = AC and AD ⊥ BC. Prove △ADB ≅ △ADC.',
                'answers' => json_encode($this->isosceles(), JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parallelogram(): array
    {
        return [
            'mode' => 'congruence',
            'play' => [
                'left_figure' => 'ABC',
                'givens' => ['ABCD is a parallelogram'],
                'figures' => [
                    [
                        'id' => 'abcd',
                        'name' => 'ABCD',
                        'caption' => 'ABCD (parallelogram)',
                        'vertices' => [
                            ['id' => 'A', 'x' => 36, 'y' => 152],
                            ['id' => 'B', 'x' => 120, 'y' => 152],
                            ['id' => 'C', 'x' => 164, 'y' => 48],
                            ['id' => 'D', 'x' => 80, 'y' => 48],
                        ],
                        'sides' => ['AB', 'BC', 'CD', 'DA', 'AC'],
                    ],
                    [
                        'id' => 'abc',
                        'name' => 'ABC',
                        'vertices' => [
                            ['id' => 'A', 'x' => 100, 'y' => 28],
                            ['id' => 'B', 'x' => 28, 'y' => 150],
                            ['id' => 'C', 'x' => 168, 'y' => 150],
                        ],
                        'sides' => ['AB', 'BC', 'AC'],
                    ],
                ],
                'steps' => [
                    [
                        'key' => 'order',
                        'type' => 'order',
                        'title' => 'Sketch',
                        'prompt' => 'ABCD is a parallelogram. Sketch △ABC. On the second triangle write C, D, A so it matches △CDA.',
                        'left' => 'ABC',
                        'choices' => ['A', 'C', 'D'],
                    ],
                    [
                        'key' => 'table',
                        'type' => 'table',
                        'title' => 'Table',
                        'prompt' => 'Which equal parts can you use? Choose each pair and its reason, then name the case.',
                        'row_count' => 3,
                        'part_choices' => ['AB = CD', 'BC = DA', 'AC = CA', 'AD = BC', 'AB = DC', '∠BAC = ∠DCA', '∠ABC = ∠CDA'],
                        'reason_choices' => [
                            'Opposite sides of parallelogram',
                            'Common side',
                            'Opposite angles of parallelogram',
                            'Alternate interior angles',
                            'Given',
                        ],
                        'case_choices' => ['SAS', 'SSS', 'ASA', 'AAS', 'RHS'],
                    ],
                ],
            ],
            'check' => [
                'order' => [
                    'letters' => ['C', 'D', 'A'],
                    'ok' => '△ABC ≅ △CDA. C sits on A, D on B, A on C.',
                    'miss' => 'Write C at A, D at B and A at C. Then △ABC matches △CDA.',
                ],
                'table' => [
                    'rows' => [
                        ['parts' => 'AB = CD', 'reason' => 'Opposite sides of parallelogram'],
                        ['parts' => 'BC = DA', 'reason' => 'Opposite sides of parallelogram'],
                        ['parts' => 'AC = CA', 'reason' => 'Common side'],
                    ],
                    'ok' => 'The condition table is right.',
                    'miss' => 'Use opposite sides AB = CD and BC = DA, and the common diagonal AC.',
                ],
                'case' => [
                    'value' => 'SSS',
                    'ok' => '△ABC ≅ △CDA by SSS. Three pairs of sides are equal.',
                    'miss' => 'Three sides: opposite sides of the parallelogram, and the common diagonal. That case is SSS.',
                    'wrong' => [
                        'SAS' => 'You have three sides, not two sides and an included angle. The case is SSS.',
                        'ASA' => 'The three facts are sides, so the case is SSS.',
                        'AAS' => 'No angle was needed. Three sides is SSS.',
                        'RHS' => 'No right angle is given. Three sides is SSS.',
                    ],
                ],
                'summary' => [
                    'statement' => '△ABC ≅ △CDA',
                    'case' => 'SSS',
                    'because' => 'In parallelogram ABCD, opposite sides are equal and AC is common. Three pairs of sides prove △ABC ≅ △CDA by SSS.',
                    'copy_order' => ['C', 'D', 'A'],
                    'ticks' => ['AB' => 1, 'CD' => 1, 'BC' => 2, 'DA' => 2, 'AC' => 3, 'CA' => 3],
                    'angles' => [],
                    'advice' => 'In your copy, mark opposite sides with / and //, and the common diagonal with ///. Write the reason “opposite sides of parallelogram” and “common side”. This is how the sum is written.',
                    'rows' => [
                        ['parts' => 'AB = CD', 'reason' => 'Opposite sides of parallelogram'],
                        ['parts' => 'BC = DA', 'reason' => 'Opposite sides of parallelogram'],
                        ['parts' => 'AC = CA', 'reason' => 'Common side'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function isosceles(): array
    {
        return [
            'mode' => 'congruence',
            'play' => [
                'left_figure' => 'ADB',
                'givens' => ['△ABC is isosceles', 'AB = AC', 'AD ⊥ BC'],
                'figures' => [
                    [
                        'id' => 'abc',
                        'name' => 'ABC',
                        'caption' => '△ABC (isosceles)',
                        'vertices' => [
                            ['id' => 'A', 'x' => 100, 'y' => 24],
                            ['id' => 'B', 'x' => 28, 'y' => 150],
                            ['id' => 'C', 'x' => 172, 'y' => 150],
                            ['id' => 'D', 'x' => 100, 'y' => 150],
                        ],
                        'sides' => ['AD', 'BD', 'CD', 'AB', 'AC', 'BC'],
                        'ticks' => ['AB' => 1, 'AC' => 1],
                        'right_angles' => ['D'],
                    ],
                    [
                        'id' => 'adb',
                        'name' => 'ADB',
                        'vertices' => [
                            ['id' => 'A', 'x' => 100, 'y' => 28],
                            ['id' => 'D', 'x' => 100, 'y' => 150],
                            ['id' => 'B', 'x' => 28, 'y' => 150],
                        ],
                        'sides' => ['AD', 'DB', 'AB'],
                        'ticks' => ['AB' => 1],
                        'right_angles' => ['D'],
                    ],
                ],
                'steps' => [
                    [
                        'key' => 'order',
                        'type' => 'order',
                        'title' => 'Sketch',
                        'prompt' => 'Sketch △ADB. On the second triangle write A, D, C so it matches △ADC. Click the right angle at D, then the matching right angle on the second triangle.',
                        'left' => 'ADB',
                        'choices' => ['A', 'C', 'D'],
                        'shade_angle' => true,
                    ],
                    [
                        'key' => 'table',
                        'type' => 'table',
                        'title' => 'Table',
                        'prompt' => 'Which equal parts can you use? Choose each pair and its reason, then name the case.',
                        'row_count' => 3,
                        'part_choices' => ['∠ADB = ∠ADC = 90°', 'AB = AC', 'AD = AD', 'BD = DC', '∠BAD = ∠CAD', 'AB = AD'],
                        'reason_choices' => [
                            'Given right angles (AD ⊥ BC)',
                            'Given (isosceles, AB = AC)',
                            'Common side',
                            'Given',
                            'Opposite sides of parallelogram',
                        ],
                        'case_choices' => ['SAS', 'SSS', 'ASA', 'AAS', 'RHS'],
                    ],
                ],
            ],
            'check' => [
                'order' => [
                    'letters' => ['A', 'D', 'C'],
                    'angle_pairs' => ['D' => 'D'],
                    'ok' => '△ADB ≅ △ADC. A sits on A, D on D, C on B, and the right angles match.',
                    'miss' => 'Write A, D, C. Colour-match the right angle at D with D on the second triangle.',
                ],
                'table' => [
                    'rows' => [
                        ['parts' => '∠ADB = ∠ADC = 90°', 'reason' => 'Given right angles (AD ⊥ BC)'],
                        ['parts' => 'AB = AC', 'reason' => 'Given (isosceles, AB = AC)'],
                        ['parts' => 'AD = AD', 'reason' => 'Common side'],
                    ],
                    'ok' => 'The condition table is right.',
                    'miss' => 'Use the two right angles, the equal hypotenuses AB = AC, and the common side AD.',
                ],
                'case' => [
                    'value' => 'RHS',
                    'ok' => '△ADB ≅ △ADC by RHS. Right angle, hypotenuse, and one more side.',
                    'miss' => 'Right angles, hypotenuses AB = AC, and common side AD. That case is RHS.',
                    'wrong' => [
                        'SAS' => 'The given angle is a right angle, and AB = AC are hypotenuses. That case is RHS.',
                        'SSS' => 'A right angle is given, so it is not three sides. The case is RHS.',
                        'ASA' => 'A right angle, hypotenuse and a side is RHS, not ASA.',
                        'AAS' => 'The right angle and hypotenuse make RHS, not AAS.',
                    ],
                ],
                'summary' => [
                    'statement' => '△ADB ≅ △ADC',
                    'case' => 'RHS',
                    'because' => 'AD ⊥ BC gives right angles at D. AB = AC are the hypotenuses, and AD is common. Right angle, hypotenuse and one side prove △ADB ≅ △ADC by RHS.',
                    'copy_order' => ['A', 'D', 'C'],
                    'ticks' => ['AB' => 1, 'AC' => 1, 'AD' => 2],
                    'angles' => [],
                    'right_angles' => ['D'],
                    'advice' => 'In your copy, mark the right angles with a square, the equal hypotenuses with /, and the common side AD with //. This is how the sum is written.',
                    'rows' => [
                        ['parts' => '∠ADB = ∠ADC = 90°', 'reason' => 'Given right angles (AD ⊥ BC)'],
                        ['parts' => 'AB = AC (hypotenuse)', 'reason' => 'Given (isosceles, AB = AC)'],
                        ['parts' => 'AD = AD', 'reason' => 'Common side'],
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
            ->whereIn('sort_order', [5, 6])
            ->delete();
    }
};
