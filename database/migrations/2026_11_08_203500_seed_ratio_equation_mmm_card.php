<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'ratios-equation')->exists()) {
            return;
        }

        $now = now();
        $source = DB::table('mmm_cards')->where('slug', 'ratios-basics')->first();
        $headId = $source->chapter_head_id
            ?? DB::table('chapter_heads')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->first(function ($row) {
                    $name = (string) $row->name;

                    return preg_match('/ratio/i', $name) === 1
                        && preg_match('/rational/i', $name) !== 1;
                })?->id;

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'ratios-equation',
            'title' => 'Ratios — ratio equation',
            'heading' => 'Ratios — ratio equation',
            'chapter_head_id' => $headId,
            'sub_heading' => 'Ratios',
            'kind' => 'ratio_equation',
            'status' => 'published',
            'accent' => 'teal',
            'method_blurb' => 'A ratio such as 2 : 3 does not give the quantities. The common factor is x, so the amounts are 2x and 3x. The condition makes the equation. Solving gives x, and x is not a quantity. Each quantity is its ratio part times x: 2 × 10 and 3 × 10.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 14,
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
                    'mode' => 'ratio_equation',
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
            $this->sum(
                true,
                'Boys and girls, 2 : 3',
                'The ratio of the number of boys to the number of girls is 2 : 3. The numbers 2 and 3 are not the numbers of students. We do not know the common factor yet.',
                'Boys to girls',
                '2 : 3',
                'Number of boys',
                'Number of girls',
                2,
                3,
                'Together there are 50 students.',
                10,
            ),
            $this->sum(
                false,
                'Apples and oranges, 3 : 5',
                'The ratio of the number of apples to the number of oranges is 3 : 5.',
                'Apples to oranges',
                '3 : 5',
                'Number of apples',
                'Number of oranges',
                3,
                5,
                'Together there are 40 fruits.',
                5,
            ),
            $this->sum(
                false,
                'Red and blue ribbons, 4 : 5',
                'The ratio of the length of red ribbon to the length of blue ribbon is 4 : 5.',
                'Red to blue',
                '4 : 5',
                'Red ribbon',
                'Blue ribbon',
                4,
                5,
                'Together the ribbons are 27 cm.',
                3,
            ),
            $this->sum(
                false,
                'Anil and Sunil, 3 : 4',
                'Anil’s money and Sunil’s money are in the ratio 3 : 4.',
                'Anil to Sunil',
                '3 : 4',
                'Anil’s money',
                'Sunil’s money',
                3,
                4,
                'Together they have ₹70.',
                10,
            ),
            $this->sum(
                false,
                'Tea and milk, 2 : 3',
                'Tea and milk in a cup are in the ratio 2 : 3.',
                'Tea to milk',
                '2 : 3',
                'Tea',
                'Milk',
                2,
                3,
                'Together there are 500 ml.',
                100,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sum(
        bool $demo,
        string $title,
        string $story,
        string $ratioLabel,
        string $ratioText,
        string $firstName,
        string $secondName,
        int $firstPart,
        int $secondPart,
        string $condition,
        int $factor,
    ): array {
        $first = $firstPart * $factor;
        $second = $secondPart * $factor;
        $total = $first + $second;

        return [
            'demo' => $demo,
            'title' => $title,
            'play' => [
                'story' => $story,
                'ratio_label' => $ratioLabel,
                'ratio_text' => $ratioText,
                'factor_line' => 'x is the common factor. It is not the first quantity, and it is not the second.',
                'rows' => [
                    ['key' => 'first', 'name' => $firstName, 'part' => $firstPart],
                    ['key' => 'second', 'name' => $secondName, 'part' => $secondPart],
                ],
                'table_cue' => 'The ratio part times x is the amount. The first number of the ratio goes with the first quantity.',
                'condition' => $condition,
                'equation_cue' => 'Add the two amounts. That sum equals the total in the condition.',
                'solve_cue' => 'Solve for x. This number is the common factor. It is not the answer for either quantity.',
                'value_cue' => 'Each quantity is its ratio part times the factor. Write the part times the factor.',
                'hints' => [
                    'first' => $firstPart.'x',
                    'second' => $secondPart.'x',
                    'equation' => $firstPart.'x + '.$secondPart.'x = '.$total,
                    'factor' => ($firstPart + $secondPart).'x = '.$total.', so x = '.$factor,
                    'first_value' => $firstPart.' × '.$factor,
                    'second_value' => $secondPart.' × '.$factor,
                ],
            ],
            'check' => [
                'shares' => [
                    'first' => $firstPart.'x',
                    'second' => $secondPart.'x',
                ],
                'equation' => $firstPart.'x+'.$secondPart.'x='.$total,
                'factor' => $factor,
                'values' => [
                    'first_value' => ['part' => $firstPart, 'factor' => $factor],
                    'second_value' => ['part' => $secondPart, 'factor' => $factor],
                ],
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'ratios-equation')->value('id');
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
