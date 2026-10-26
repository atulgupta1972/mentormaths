<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'congruence-sas')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'congruence-sas',
            'title' => 'Congruent triangles — SAS',
            'heading' => 'Congruent triangles — SAS',
            'kind' => 'congruence',
            'status' => 'published',
            'accent' => 'teal',
            'method_blurb' => 'Redraw the triangles with the equal parts marked. Write each pair in a table with its reason and whether it is a side or an angle. The pattern of the table names the case: SAS, SSS, ASA, AAS, or RHS.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 10,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 1,
            'difficulty' => 'demo',
            'is_demo' => true,
            'question_text' => 'Prove △ABC ≅ △PQR. Given: AB = PQ = 5 cm, AC = PR = 6 cm, and ∠BAC = ∠QPR.',
            'answers' => json_encode($this->answers(), JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        return [
            'mode' => 'congruence',
            'play' => [
                'givens' => ['AB = PQ = 5 cm', 'AC = PR = 6 cm', '∠BAC = ∠QPR'],
                'figures' => [
                    [
                        'id' => 'left',
                        'name' => 'ABC',
                        'vertices' => [
                            ['id' => 'A', 'x' => 96, 'y' => 28],
                            ['id' => 'B', 'x' => 28, 'y' => 150],
                            ['id' => 'C', 'x' => 158, 'y' => 150],
                        ],
                        'sides' => ['AB', 'BC', 'AC'],
                    ],
                    [
                        'id' => 'right',
                        'name' => 'PQR',
                        'vertices' => [
                            ['id' => 'P', 'x' => 100, 'y' => 24],
                            ['id' => 'Q', 'x' => 22, 'y' => 152],
                            ['id' => 'R', 'x' => 176, 'y' => 140],
                        ],
                        'sides' => ['PQ', 'QR', 'PR'],
                    ],
                ],
                'steps' => [
                    [
                        'key' => 'order',
                        'type' => 'order',
                        'title' => 'Order',
                        'prompt' => 'Write the second triangle so matching corners stay in the same order. The equal angle is at A and at P.',
                        'left' => 'ABC',
                        'choices' => ['P', 'Q', 'R'],
                    ],
                    [
                        'key' => 'marks',
                        'type' => 'marks',
                        'title' => 'Mark',
                        'prompt' => 'Redraw the marks. Put 5 cm on the equal 5 cm sides, 6 cm on the equal 6 cm sides, and the angle mark on the equal angles.',
                        'tools' => [
                            ['id' => '5', 'label' => '5 cm', 'kind' => 'side'],
                            ['id' => '6', 'label' => '6 cm', 'kind' => 'side'],
                            ['id' => 'angle', 'label' => 'Angle', 'kind' => 'angle'],
                        ],
                    ],
                    [
                        'key' => 'table',
                        'type' => 'table',
                        'title' => 'Table',
                        'prompt' => 'Write the 5 cm sides, then the 6 cm sides, then the equal angles. Give the reason, and mark each row Side or Angle.',
                        'row_count' => 3,
                        'part_choices' => ['AB = PQ', 'AC = PR', 'BC = QR', '∠BAC = ∠QPR', '∠ABC = ∠PQR', '∠ACB = ∠PRQ'],
                        'reason_choices' => [
                            'Given equal sides of 5 cm',
                            'Given equal sides of 6 cm',
                            'Given equal angles',
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
                    'letters' => ['P', 'Q', 'R'],
                    'ok' => '△ABC ≅ △PQR. A matches P, B matches Q, C matches R.',
                    'miss' => 'The equal angle is at A and at P, so A lines up with P. Then B lines up with Q and C with R.',
                ],
                'marks' => [
                    'placed' => [
                        'AB' => '5',
                        'PQ' => '5',
                        'AC' => '6',
                        'PR' => '6',
                        'A' => 'angle',
                        'P' => 'angle',
                    ],
                    'ok' => 'The 5 cm sides, the 6 cm sides, and the included angles are marked.',
                    'miss' => '5 cm goes on AB and PQ. 6 cm goes on AC and PR. The angle mark goes on A and P.',
                ],
                'table' => [
                    'rows' => [
                        ['parts' => 'AB = PQ', 'reason' => 'Given equal sides of 5 cm', 'type' => 'Side'],
                        ['parts' => 'AC = PR', 'reason' => 'Given equal sides of 6 cm', 'type' => 'Side'],
                        ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles', 'type' => 'Angle'],
                    ],
                    'ok' => 'Two sides and the angle between them are in the table.',
                    'miss' => 'Row 1 is the 5 cm sides. Row 2 is the 6 cm sides. Row 3 is ∠BAC = ∠QPR. Each reason is “given”.',
                ],
                'case' => [
                    'value' => 'SAS',
                    'ok' => '△ABC ≅ △PQR by SAS. The angle sits between the 5 cm side and the 6 cm side.',
                    'miss' => 'Read the table: two sides and the included angle. That case is SAS.',
                    'wrong' => [
                        'SSS' => 'The third fact is an angle, so it is not three sides.',
                        'ASA' => 'The angle is between the two sides, so the case is SAS.',
                        'AAS' => 'The angle sits between the two given sides. That is SAS.',
                        'RHS' => 'RHS needs a right angle and the hypotenuse. This figure has neither.',
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

        DB::table('student_mmm_progress')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_card_syllabus_chapter')->where('mmm_card_id', $cardId)->delete();
        if (DB::getSchemaBuilder()->hasTable('mmm_card_grade_level')) {
            DB::table('mmm_card_grade_level')->where('mmm_card_id', $cardId)->delete();
        }
        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->delete();
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
