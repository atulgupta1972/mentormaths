<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'making-an-equation')->exists()) {
            return;
        }

        $now = now();
        $headId = DB::table('chapter_heads')->where('name', 'like', '%Linear Equation%')->orderBy('id')->value('id')
            ?? DB::table('chapter_heads')->where('name', 'like', '%Equation%')->orderBy('id')->value('id');

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'making-an-equation',
            'title' => 'Making an equation — say it with x',
            'heading' => 'Making an equation — say it with x',
            'chapter_head_id' => $headId,
            'sub_heading' => 'Making an equation',
            'kind' => 'make_equation',
            'status' => 'published',
            'accent' => 'amber',
            'method_blurb' => 'First say the amount with a number, so you can see the more, less, times, half, higher, or lower. Then say that same amount with x in place of the number. Only after that, write the equation: what I have + the other amount = the total.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 11,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [];
        foreach ($this->exercises() as $index => $exercise) {
            $rows[] = [
                'mmm_card_id' => $cardId,
                'sort_order' => $index + 1,
                'difficulty' => $exercise['demo'] ? 'demo' : 'easy',
                'is_demo' => $exercise['demo'],
                'question_text' => $exercise['title'],
                'answers' => json_encode([
                    'mode' => 'make_equation',
                    'play' => [
                        'phrase' => $exercise['phrase'],
                        'number_story' => $exercise['number_story'],
                        'number_line' => $exercise['number_line'],
                        'x_story' => $exercise['x_story'],
                        'define_label' => $exercise['define_label'],
                        'define_cue' => $exercise['define_cue'],
                        'define_hint' => $exercise['define_hint'],
                        'equation_story' => $exercise['equation_story'],
                        'equation_cue' => 'My amount + the other amount = the total.',
                        'equation_hint' => $exercise['equation_hint'],
                    ],
                    'check' => [
                        'define' => $exercise['define'],
                        'equation' => $exercise['equation'],
                        'define_wrong' => $exercise['define_wrong'],
                        'equation_wrong' => 'Add what I have and the amount you wrote with x. Put the total on the other side of =.',
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('mmm_card_exercises')->insert($rows);

        if (DB::getSchemaBuilder()->hasTable('mmm_card_grade_level') && DB::getSchemaBuilder()->hasTable('grade_levels')) {
            $gradeIds = DB::table('grade_levels')
                ->get(['id', 'name'])
                ->filter(fn ($row) => preg_match('/\b(?:class|grade)\s*([6-8])\b/i', (string) $row->name))
                ->pluck('id')
                ->all();

            foreach ($gradeIds as $gradeId) {
                DB::table('mmm_card_grade_level')->insert([
                    'mmm_card_id' => $cardId,
                    'grade_level_id' => $gradeId,
                ]);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exercises(): array
    {
        return [
            [
                'demo' => true,
                'title' => 'More than — pencils',
                'phrase' => 'more than',
                'number_story' => 'I have 5 pencils. My friend has 2 more than me.',
                'number_line' => 'My friend has 5 + 2 = 7',
                'x_story' => 'I have x pencils. My friend has 2 more than me.',
                'define_label' => 'My friend has',
                'define_cue' => 'Same as 5 + 2. The 5 is now x.',
                'define_hint' => 'Write x + 2. The 2 more stays, and x takes the place of 5.',
                'define' => 'x+2',
                'define_wrong' => 'Keep the + 2 from the number line, and write x where the 5 was.',
                'equation_story' => 'I have x pencils and my friend has 2 more than me. In total we have 10 pencils.',
                'equation_hint' => 'Mine is x. My friend has x + 2. Together: x + (x + 2) = 10.',
                'equation' => 'x+(x+2)=10',
            ],
            [
                'demo' => false,
                'title' => 'Less than — marbles',
                'phrase' => 'less than',
                'number_story' => 'I have 9 marbles. My friend has 4 less than me.',
                'number_line' => 'My friend has 9 − 4 = 5',
                'x_story' => 'I have x marbles. My friend has 4 less than me.',
                'define_label' => 'My friend has',
                'define_cue' => 'Same as 9 − 4. The 9 is now x.',
                'define_hint' => 'Write x − 4. Less than means subtract, and x takes the place of 9.',
                'define' => 'x-4',
                'define_wrong' => 'Less than means subtract. Keep the − 4, and write x where the 9 was.',
                'equation_story' => 'I have x marbles and my friend has 4 less than me. In total we have 14 marbles.',
                'equation_hint' => 'Mine is x. My friend has x − 4. Together: x + (x − 4) = 14.',
                'equation' => 'x+(x-4)=14',
            ],
            [
                'demo' => false,
                'title' => 'Times — stickers',
                'phrase' => 'times',
                'number_story' => 'I have 5 stickers. My friend has 3 times as many as me.',
                'number_line' => 'My friend has 5 × 3 = 15',
                'x_story' => 'I have x stickers. My friend has 3 times as many as me.',
                'define_label' => 'My friend has',
                'define_cue' => 'Same as 5 × 3. The 5 is now x.',
                'define_hint' => 'Write 3x. Times means multiply, and x takes the place of 5.',
                'define' => '3x',
                'define_wrong' => 'Times means multiply. 3 times the number was 5 × 3, so 3 times x is 3x.',
                'equation_story' => 'I have x stickers and my friend has 3 times as many as me. In total we have 20 stickers.',
                'equation_hint' => 'Mine is x. My friend has 3x. Together: x + 3x = 20.',
                'equation' => 'x+3x=20',
            ],
            [
                'demo' => false,
                'title' => 'Half of — rupees',
                'phrase' => '1/2 of',
                'number_story' => 'I have 12 rupees. My sister has 1/2 of what I have.',
                'number_line' => 'My sister has 1/2 × 12 = 6',
                'x_story' => 'I have x rupees. My sister has 1/2 of what I have.',
                'define_label' => 'My sister has',
                'define_cue' => 'Same as 1/2 of 12. The 12 is now x.',
                'define_hint' => 'Write x/2. Half of a number is that number divided by 2, so half of x is x/2.',
                'define' => 'x/2',
                'define_wrong' => 'Of a half means divide by 2. Half of 12 was 6, so half of x is x/2.',
                'equation_story' => 'I have x rupees and my sister has 1/2 of what I have. In total we have 18 rupees.',
                'equation_hint' => 'Mine is x. My sister has x/2. Together: x + x/2 = 18.',
                'equation' => 'x+x/2=18',
            ],
            [
                'demo' => false,
                'title' => 'Higher — the shelf',
                'phrase' => 'higher',
                'number_story' => 'The table is 70 cm high. The shelf is 15 cm higher.',
                'number_line' => 'The shelf is 70 + 15 = 85',
                'x_story' => 'The table is x cm high. The shelf is 15 cm higher.',
                'define_label' => 'The shelf is',
                'define_cue' => 'Higher means more. Same as 70 + 15. The 70 is now x.',
                'define_hint' => 'Write x + 15. Higher means add, just like more than.',
                'define' => 'x+15',
                'define_wrong' => 'Higher means add. Keep the + 15, and write x where the 70 was.',
                'equation_story' => 'The table is x cm and the shelf is 15 cm higher. In total the two heights are 155 cm.',
                'equation_hint' => 'The table is x. The shelf is x + 15. Together: x + (x + 15) = 155.',
                'equation' => 'x+(x+15)=155',
            ],
            [
                'demo' => false,
                'title' => 'Lower — the cap',
                'phrase' => 'lower',
                'number_story' => 'A bag costs 80 rupees. A cap costs 25 rupees lower.',
                'number_line' => 'The cap costs 80 − 25 = 55',
                'x_story' => 'A bag costs x rupees. A cap costs 25 rupees lower.',
                'define_label' => 'The cap costs',
                'define_cue' => 'Lower means less. Same as 80 − 25. The 80 is now x.',
                'define_hint' => 'Write x − 25. Lower means subtract, just like less than.',
                'define' => 'x-25',
                'define_wrong' => 'Lower means subtract. Keep the − 25, and write x where the 80 was.',
                'equation_story' => 'A bag costs x rupees and a cap costs 25 rupees lower. In total they cost 135 rupees.',
                'equation_hint' => 'The bag is x. The cap is x − 25. Together: x + (x − 25) = 135.',
                'equation' => 'x+(x-25)=135',
            ],
            [
                'demo' => false,
                'title' => 'More than — pens',
                'phrase' => 'more than',
                'number_story' => 'I have 7 pens. My friend has 3 more than me.',
                'number_line' => 'My friend has 7 + 3 = 10',
                'x_story' => 'I have x pens. My friend has 3 more than me.',
                'define_label' => 'My friend has',
                'define_cue' => 'Same as 7 + 3. The 7 is now x.',
                'define_hint' => 'Write x + 3. More than means add.',
                'define' => 'x+3',
                'define_wrong' => 'Keep the + 3 from the number line, and write x where the 7 was.',
                'equation_story' => 'I have x pens and my friend has 3 more than me. In total we have 17 pens.',
                'equation_hint' => 'Mine is x. My friend has x + 3. Together: x + (x + 3) = 17.',
                'equation' => 'x+(x+3)=17',
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'making-an-equation')->value('id');
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
