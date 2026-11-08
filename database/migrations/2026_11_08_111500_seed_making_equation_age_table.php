<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'making-an-equation-age')->exists()) {
            return;
        }

        $now = now();
        $source = DB::table('mmm_cards')->where('slug', 'making-an-equation')->first();
        $headId = $source->chapter_head_id
            ?? DB::table('chapter_heads')->where('name', 'like', '%Linear Equation%')->orderBy('id')->value('id')
            ?? DB::table('chapter_heads')->where('name', 'like', '%Equation%')->orderBy('id')->value('id');

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'making-an-equation-age',
            'title' => 'Making an equation — age problems',
            'heading' => 'Making an equation — age problems',
            'chapter_head_id' => $headId,
            'sub_heading' => 'Making an equation',
            'kind' => 'age_table',
            'status' => 'published',
            'accent' => 'amber',
            'method_blurb' => 'Age problems start as a table. The present age you are calling x goes in the first age column. Times and “half of” are also present ages. After n years, add n to every age in that row. Ago, or before, n years, subtract n from every age. The condition tells you which column to add. That sum is the equation.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 12,
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
                    'mode' => 'age_table',
                    'play' => $exercise['play'],
                    'check' => $exercise['check'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('mmm_card_exercises')->insert($rows);

        if ($source && DB::getSchemaBuilder()->hasTable('mmm_card_grade_level')) {
            $gradeIds = DB::table('mmm_card_grade_level')->where('mmm_card_id', $source->id)->pluck('grade_level_id');
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
                'title' => 'Age words — now, after, ago',
                'play' => [
                    'phrases' => ['after', 'ago'],
                    'story' => 'My present age is x years. Fill the age now, after 5 years, and 5 years ago.',
                    'name_heading' => 'When',
                    'columns' => [
                        ['key' => 'age', 'label' => 'Age'],
                    ],
                    'rows' => [
                        ['key' => 'now', 'name' => 'Now'],
                        ['key' => 'after', 'name' => 'After 5 years'],
                        ['key' => 'ago', 'name' => '5 years ago (before)'],
                    ],
                    'table_cue' => 'After means add 5 to x. Ago, or before, means subtract 5 from x.',
                    'hints' => [
                        'now.age' => 'The present age is x.',
                        'after.age' => 'After 5 years the age is x + 5.',
                        'ago.age' => '5 years ago the age was x − 5.',
                    ],
                ],
                'check' => [
                    'cells' => [
                        'now.age' => 'x',
                        'after.age' => 'x+5',
                        'ago.age' => 'x-5',
                    ],
                    'wrong' => [
                        'now.age' => 'The present age in this story is x.',
                        'after.age' => 'After 5 years, add 5. The age is x + 5.',
                        'ago.age' => 'Ago, or before, means subtract. 5 years ago the age was x − 5.',
                    ],
                ],
            ],
            [
                'demo' => true,
                'title' => 'Times and after — Rakesh and Sanjay',
                'play' => [
                    'phrases' => ['times', 'after'],
                    'story' => 'Rakesh is 2 times as old as Sanjay. Take Sanjay’s present age as x. After 5 years, the sum of their ages is 40.',
                    'columns' => [
                        ['key' => 'now', 'label' => 'Current age'],
                        ['key' => 'later', 'label' => 'Age after 5 years'],
                    ],
                    'rows' => [
                        ['key' => 'sanjay', 'name' => 'Sanjay'],
                        ['key' => 'rakesh', 'name' => 'Rakesh'],
                    ],
                    'table_cue' => 'Present age first. Rakesh is 2 times Sanjay. After 5 years, add 5 to each present age.',
                    'condition' => 'After 5 years, the sum of their ages is 40.',
                    'equation_cue' => 'Add the two ages in the “after 5 years” column.',
                    'equation_hint' => '(x + 5) + (2x + 5) = 40',
                    'hints' => [
                        'sanjay.now' => 'Sanjay’s present age is x.',
                        'sanjay.later' => 'After 5 years, add 5 to x. Sanjay is x + 5.',
                        'rakesh.now' => 'Rakesh is 2 times Sanjay, so his present age is 2x.',
                        'rakesh.later' => 'Add 5 to 2x. Rakesh after 5 years is 2x + 5.',
                    ],
                ],
                'check' => [
                    'cells' => [
                        'sanjay.now' => 'x',
                        'sanjay.later' => 'x+5',
                        'rakesh.now' => '2x',
                        'rakesh.later' => '2x+5',
                    ],
                    'equation' => '(x+5)+(2x+5)=40',
                    'wrong' => [
                        'sanjay.now' => 'Sanjay’s present age is x.',
                        'sanjay.later' => 'After 5 years, add 5 to Sanjay’s present age: x + 5.',
                        'rakesh.now' => '2 times Sanjay’s age is 2x.',
                        'rakesh.later' => 'Add 5 to Rakesh’s present age: 2x + 5.',
                        'equation' => 'Add the after-5 column: (x + 5) + (2x + 5) = 40.',
                    ],
                ],
            ],
            [
                'demo' => false,
                'title' => 'Half of, and after',
                'play' => [
                    'phrases' => ['1/2 of', 'after'],
                    'story' => 'Meera is 1/2 of her brother Arun’s age. Arun’s present age is x. After 6 years, the sum of their ages is 36.',
                    'columns' => [
                        ['key' => 'now', 'label' => 'Current age'],
                        ['key' => 'later', 'label' => 'Age after 6 years'],
                    ],
                    'rows' => [
                        ['key' => 'arun', 'name' => 'Arun'],
                        ['key' => 'meera', 'name' => 'Meera'],
                    ],
                    'table_cue' => 'Half of x is x/2. After 6 years, add 6 to each present age.',
                    'condition' => 'After 6 years, the sum of their ages is 36.',
                    'equation_cue' => 'Add the two ages in the “after 6 years” column.',
                    'equation_hint' => '(x + 6) + (x/2 + 6) = 36',
                    'hints' => [
                        'arun.now' => 'Arun’s present age is x.',
                        'arun.later' => 'After 6 years Arun is x + 6.',
                        'meera.now' => 'Meera is half of Arun, so her present age is x/2.',
                        'meera.later' => 'Add 6 to x/2. Meera after 6 years is x/2 + 6.',
                    ],
                ],
                'check' => [
                    'cells' => [
                        'arun.now' => 'x',
                        'arun.later' => 'x+6',
                        'meera.now' => 'x/2',
                        'meera.later' => 'x/2+6',
                    ],
                    'equation' => '(x+6)+(x/2+6)=36',
                    'wrong' => [
                        'meera.now' => '1/2 of x is x/2.',
                        'meera.later' => 'Add 6 to Meera’s present age: x/2 + 6.',
                        'arun.later' => 'After 6 years, add 6 to x.',
                        'equation' => 'Add the after-6 column: (x + 6) + (x/2 + 6) = 36.',
                    ],
                ],
            ],
            [
                'demo' => false,
                'title' => 'Times and ago',
                'play' => [
                    'phrases' => ['times', 'ago'],
                    'story' => 'A father is 4 times as old as his son. The son’s present age is x. 5 years ago, the sum of their ages was 40.',
                    'columns' => [
                        ['key' => 'now', 'label' => 'Current age'],
                        ['key' => 'ago', 'label' => 'Age 5 years ago'],
                    ],
                    'rows' => [
                        ['key' => 'son', 'name' => 'Son'],
                        ['key' => 'father', 'name' => 'Father'],
                    ],
                    'table_cue' => 'Ago, or before, means subtract. Take 5 away from each present age.',
                    'condition' => '5 years ago, the sum of their ages was 40.',
                    'equation_cue' => 'Add the two ages in the “5 years ago” column.',
                    'equation_hint' => '(x − 5) + (4x − 5) = 40',
                    'hints' => [
                        'son.now' => 'The son’s present age is x.',
                        'son.ago' => '5 years ago the son was x − 5.',
                        'father.now' => 'The father is 4 times the son, so his present age is 4x.',
                        'father.ago' => 'Subtract 5 from 4x. The father 5 years ago was 4x − 5.',
                    ],
                ],
                'check' => [
                    'cells' => [
                        'son.now' => 'x',
                        'son.ago' => 'x-5',
                        'father.now' => '4x',
                        'father.ago' => '4x-5',
                    ],
                    'equation' => '(x-5)+(4x-5)=40',
                    'wrong' => [
                        'son.ago' => '5 years ago, subtract 5 from x.',
                        'father.now' => '4 times the son’s age is 4x.',
                        'father.ago' => 'Subtract 5 from 4x: 4x − 5.',
                        'equation' => 'Add the ago column: (x − 5) + (4x − 5) = 40.',
                    ],
                ],
            ],
            [
                'demo' => false,
                'title' => 'Half of, and ago',
                'play' => [
                    'phrases' => ['1/2 of', 'ago'],
                    'story' => 'Meera is 1/2 of Neha’s age. Neha’s present age is x. 6 years ago, the sum of their ages was 18.',
                    'columns' => [
                        ['key' => 'now', 'label' => 'Current age'],
                        ['key' => 'ago', 'label' => 'Age 6 years ago'],
                    ],
                    'rows' => [
                        ['key' => 'neha', 'name' => 'Neha'],
                        ['key' => 'meera', 'name' => 'Meera'],
                    ],
                    'table_cue' => 'Half of x is x/2. 6 years ago, subtract 6 from each present age.',
                    'condition' => '6 years ago, the sum of their ages was 18.',
                    'equation_cue' => 'Add the two ages in the “6 years ago” column.',
                    'equation_hint' => '(x − 6) + (x/2 − 6) = 18',
                    'hints' => [
                        'neha.now' => 'Neha’s present age is x.',
                        'neha.ago' => '6 years ago Neha was x − 6.',
                        'meera.now' => 'Meera is half of Neha, so her present age is x/2.',
                        'meera.ago' => 'Subtract 6 from x/2. Meera 6 years ago was x/2 − 6.',
                    ],
                ],
                'check' => [
                    'cells' => [
                        'neha.now' => 'x',
                        'neha.ago' => 'x-6',
                        'meera.now' => 'x/2',
                        'meera.ago' => 'x/2-6',
                    ],
                    'equation' => '(x-6)+(x/2-6)=18',
                    'wrong' => [
                        'meera.now' => '1/2 of x is x/2.',
                        'meera.ago' => 'Subtract 6 from x/2: x/2 − 6.',
                        'neha.ago' => '6 years ago, subtract 6 from x.',
                        'equation' => 'Add the ago column: (x − 6) + (x/2 − 6) = 18.',
                    ],
                ],
            ],
            [
                'demo' => false,
                'title' => '5 years older, and after',
                'play' => [
                    'phrases' => ['older', 'after'],
                    'story' => 'Sanjay is 5 years older than Rakesh. Take Rakesh’s present age as x. After 2 years, the sum of their ages is 29.',
                    'columns' => [
                        ['key' => 'now', 'label' => 'Current age'],
                        ['key' => 'later', 'label' => 'Age after 2 years'],
                    ],
                    'rows' => [
                        ['key' => 'rakesh', 'name' => 'Rakesh'],
                        ['key' => 'sanjay', 'name' => 'Sanjay'],
                    ],
                    'table_cue' => 'Sanjay’s present age is x + 5. After 2 years, add 2 to each present age. x + 5, then add 2, is x + 7.',
                    'condition' => 'After 2 years, the sum of their ages is 29.',
                    'equation_cue' => 'Add the two ages in the “after 2 years” column.',
                    'equation_hint' => '(x + 2) + (x + 7) = 29',
                    'hints' => [
                        'rakesh.now' => 'Rakesh’s present age is x.',
                        'rakesh.later' => 'After 2 years Rakesh is x + 2.',
                        'sanjay.now' => 'Sanjay is 5 years older, so his present age is x + 5.',
                        'sanjay.later' => 'Add 2 to x + 5. Sanjay after 2 years is x + 7.',
                    ],
                ],
                'check' => [
                    'cells' => [
                        'rakesh.now' => 'x',
                        'rakesh.later' => 'x+2',
                        'sanjay.now' => 'x+5',
                        'sanjay.later' => 'x+7',
                    ],
                    'equation' => '(x+2)+(x+7)=29',
                    'wrong' => [
                        'sanjay.now' => '5 years older than x is x + 5.',
                        'sanjay.later' => 'Add 2 to the present age x + 5. That is x + 7, not x + 5 + x.',
                        'rakesh.later' => 'After 2 years, add 2 to x.',
                        'equation' => 'Add the after-2 column: (x + 2) + (x + 7) = 29.',
                    ],
                ],
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'making-an-equation-age')->value('id');
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
