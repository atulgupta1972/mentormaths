<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'making-an-equation-age')->value('id');
        if (! $cardId) {
            return;
        }

        $exists = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 7)
            ->exists();
        if ($exists) {
            return;
        }

        $now = now();
        $items = [];
        $check = [];
        foreach ($this->sums() as $index => $sum) {
            $key = 's'.($index + 1);
            $people = [];
            foreach ($sum['people'] as $person) {
                $people[] = ['key' => $person['key'], 'name' => $person['name']];
                $check[$key.'.'.$person['key']] = (string) $person['age'];
            }
            $items[] = [
                'key' => $key,
                'story' => $sum['story'],
                'columns' => $sum['columns'],
                'people' => $people,
                'hint' => $sum['hint'],
            ];
        }

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 7,
            'difficulty' => 'easy',
            'is_demo' => false,
            'question_text' => 'Ten age sums. Make each table in your copy, solve it, and write the present age of each person.',
            'answers' => json_encode([
                'mode' => 'age_answers',
                'play' => [
                    'page_blurb' => 'The headings show the table to draw in your copy. Fill that table, solve it, and write only the present age of each person.',
                    'items' => $items,
                ],
                'check' => $check,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sums(): array
    {
        return [
            [
                'story' => 'Rakesh is 2 times as old as Sanjay. After 5 years, the sum of their ages is 40.',
                'columns' => ['Name', 'Current age', 'Age after 5 years'],
                'people' => [
                    ['key' => 'sanjay', 'name' => 'Sanjay', 'age' => 10],
                    ['key' => 'rakesh', 'name' => 'Rakesh', 'age' => 20],
                ],
                'hint' => 'Sanjay is x, Rakesh is 2x. After 5 years: (x + 5) + (2x + 5) = 40. Present ages: Sanjay 10, Rakesh 20.',
            ],
            [
                'story' => 'Meera is 1/2 of her brother Arun’s age. After 6 years, the sum of their ages is 36.',
                'columns' => ['Name', 'Current age', 'Age after 6 years'],
                'people' => [
                    ['key' => 'arun', 'name' => 'Arun', 'age' => 16],
                    ['key' => 'meera', 'name' => 'Meera', 'age' => 8],
                ],
                'hint' => 'Arun is x, Meera is x/2. After 6 years: (x + 6) + (x/2 + 6) = 36. Present ages: Arun 16, Meera 8.',
            ],
            [
                'story' => 'A father is 4 times as old as his son. 5 years ago, the sum of their ages was 40.',
                'columns' => ['Name', 'Current age', 'Age 5 years ago'],
                'people' => [
                    ['key' => 'son', 'name' => 'Son', 'age' => 10],
                    ['key' => 'father', 'name' => 'Father', 'age' => 40],
                ],
                'hint' => 'Son is x, father is 4x. Five years ago: (x − 5) + (4x − 5) = 40. Present ages: Son 10, Father 40.',
            ],
            [
                'story' => 'Meera is 1/2 of Neha’s age. 6 years ago, the sum of their ages was 18.',
                'columns' => ['Name', 'Current age', 'Age 6 years ago'],
                'people' => [
                    ['key' => 'neha', 'name' => 'Neha', 'age' => 20],
                    ['key' => 'meera', 'name' => 'Meera', 'age' => 10],
                ],
                'hint' => 'Neha is x, Meera is x/2. Six years ago: (x − 6) + (x/2 − 6) = 18. Present ages: Neha 20, Meera 10.',
            ],
            [
                'story' => 'Sanjay is 5 years older than Rakesh. After 2 years, the sum of their ages is 29.',
                'columns' => ['Name', 'Current age', 'Age after 2 years'],
                'people' => [
                    ['key' => 'rakesh', 'name' => 'Rakesh', 'age' => 10],
                    ['key' => 'sanjay', 'name' => 'Sanjay', 'age' => 15],
                ],
                'hint' => 'Rakesh is x, Sanjay is x + 5. After 2 years: (x + 2) + (x + 7) = 29. Present ages: Rakesh 10, Sanjay 15.',
            ],
            [
                'story' => 'A mother is 3 times as old as her daughter. After 4 years, the sum of their ages is 48.',
                'columns' => ['Name', 'Current age', 'Age after 4 years'],
                'people' => [
                    ['key' => 'daughter', 'name' => 'Daughter', 'age' => 10],
                    ['key' => 'mother', 'name' => 'Mother', 'age' => 30],
                ],
                'hint' => 'Daughter is x, mother is 3x. After 4 years: (x + 4) + (3x + 4) = 48. Present ages: Daughter 10, Mother 30.',
            ],
            [
                'story' => 'An uncle is 3 times as old as his nephew. 4 years before today, the sum of their ages was 40.',
                'columns' => ['Name', 'Current age', 'Age 4 years ago'],
                'people' => [
                    ['key' => 'nephew', 'name' => 'Nephew', 'age' => 12],
                    ['key' => 'uncle', 'name' => 'Uncle', 'age' => 36],
                ],
                'hint' => 'Nephew is x, uncle is 3x. Four years ago: (x − 4) + (3x − 4) = 40. Present ages: Nephew 12, Uncle 36.',
            ],
            [
                'story' => 'A sister is 1/2 of her brother’s age. After 2 years, the sum of their ages is 22.',
                'columns' => ['Name', 'Current age', 'Age after 2 years'],
                'people' => [
                    ['key' => 'brother', 'name' => 'Brother', 'age' => 12],
                    ['key' => 'sister', 'name' => 'Sister', 'age' => 6],
                ],
                'hint' => 'Brother is x, sister is x/2. After 2 years: (x + 2) + (x/2 + 2) = 22. Present ages: Brother 12, Sister 6.',
            ],
            [
                'story' => 'Priya is 6 years older than Aman. 3 years ago, the sum of their ages was 20.',
                'columns' => ['Name', 'Current age', 'Age 3 years ago'],
                'people' => [
                    ['key' => 'aman', 'name' => 'Aman', 'age' => 10],
                    ['key' => 'priya', 'name' => 'Priya', 'age' => 16],
                ],
                'hint' => 'Aman is x, Priya is x + 6. Three years ago: (x − 3) + (x + 3) = 20. Present ages: Aman 10, Priya 16.',
            ],
            [
                'story' => 'A grandfather is 5 times as old as his grandson. After 5 years, the sum of their ages is 70.',
                'columns' => ['Name', 'Current age', 'Age after 5 years'],
                'people' => [
                    ['key' => 'grandson', 'name' => 'Grandson', 'age' => 10],
                    ['key' => 'grandfather', 'name' => 'Grandfather', 'age' => 50],
                ],
                'hint' => 'Grandson is x, grandfather is 5x. After 5 years: (x + 5) + (5x + 5) = 70. Present ages: Grandson 10, Grandfather 50.',
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'making-an-equation-age')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 7)
            ->delete();
    }
};
