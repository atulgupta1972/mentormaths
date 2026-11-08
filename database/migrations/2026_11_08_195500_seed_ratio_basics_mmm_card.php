<?php

use App\Support\ConceptBranch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $headId = $this->ratioHeadId($now);

        $existingId = DB::table('mmm_cards')->where('slug', 'ratios-basics')->value('id');
        if ($existingId) {
            DB::table('mmm_cards')->where('id', $existingId)->whereNull('chapter_head_id')->update([
                'chapter_head_id' => $headId,
                'sub_heading' => 'Ratios',
                'updated_at' => $now,
            ]);

            return;
        }

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'ratios-basics',
            'title' => 'Ratios — basics',
            'heading' => 'Ratios — basics',
            'chapter_head_id' => $headId,
            'sub_heading' => 'Ratios',
            'kind' => 'ratio',
            'status' => 'published',
            'accent' => 'teal',
            'method_blurb' => 'A ratio compares two quantities of the same kind. The first word is the first number, so boys to girls is boys : girls. Divide both numbers by the same number until you cannot. Both quantities must use the same unit first: 1 kg to 300 g is 1000 g to 300 g, which is 10 : 3.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 13,
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
                'answers' => json_encode($exercise['answers'], JSON_UNESCAPED_UNICODE),
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
        $single = function (
            bool $demo,
            string $title,
            string $phrase,
            string $explain,
            ?string $worked,
            string $story,
            string $cue,
            string $hint,
            string $ratio,
            array $sameUnit = [],
        ): array {
            return [
                'demo' => $demo,
                'title' => $title,
                'answers' => [
                    'mode' => 'ratio',
                    'play' => [
                        'phrase' => $phrase,
                        'explain' => $explain,
                        'worked' => $worked,
                        'story' => $story,
                        'cue' => $cue,
                        'hint' => $hint,
                    ],
                    'check' => [
                        'ratio' => $ratio,
                        'same_unit' => $sameUnit,
                    ],
                ],
            ];
        };

        $practice = [];
        $answers = [];
        $sameUnit = [];
        foreach ($this->practiceSums() as $index => $sum) {
            $key = 's'.($index + 1);
            $practice[] = [
                'key' => $key,
                'story' => $sum['story'],
                'hint' => $sum['hint'],
            ];
            $answers[$key] = $sum['ratio'];
            if ($sum['trap'] !== null) {
                $sameUnit[$key] = [$sum['trap']];
            }
        }

        return [
            $single(
                true,
                'What a ratio is — boys and girls',
                'same kind',
                'A ratio compares two quantities of the same kind. The first word is the first number.',
                '6 pens and 9 pencils. Pens to pencils = 6 : 9 = 2 : 3.',
                'A class has 12 boys and 18 girls. Write the ratio of boys to girls.',
                'Boys first, then girls. Then divide both numbers by the same number.',
                '12 : 18 = 2 : 3.',
                '2:3',
            ),
            $single(
                false,
                'Price',
                'price',
                'Price is a quantity too. Compare the two prices in the order asked.',
                null,
                'A pen costs ₹20. A book costs ₹30. Write the ratio of the price of the pen to the price of the book.',
                'Pen first, then book.',
                '20 : 30 = 2 : 3.',
                '2:3',
            ),
            $single(
                false,
                'Weight',
                'weight',
                'When both weights already use kilograms, write those numbers in order.',
                null,
                'A bag of rice is 8 kg. A bag of wheat is 12 kg. Write the ratio of rice to wheat.',
                'Rice first, then wheat.',
                '8 : 12 = 2 : 3.',
                '2:3',
            ),
            $single(
                false,
                'Length',
                'length',
                'When both lengths already use centimetres, write those numbers in order.',
                null,
                'A ribbon is 15 cm. A stick is 25 cm. Write the ratio of the ribbon to the stick.',
                'Ribbon first, then stick.',
                '15 : 25 = 3 : 5.',
                '3:5',
            ),
            $single(
                false,
                'Time',
                'time',
                'When both times already use minutes, write those numbers in order.',
                null,
                'A break is 20 minutes. A class is 30 minutes. Write the ratio of the break to the class.',
                'Break first, then class.',
                '20 : 30 = 2 : 3.',
                '2:3',
            ),
            $single(
                true,
                'Same unit — kilograms and grams',
                'same unit',
                'Both quantities must use the same unit before you compare them. 1 kg is not the number 1 next to 300 g.',
                '1 m compared with 25 cm. 1 m = 100 cm, so 100 : 25 = 4 : 1.',
                'Write the ratio of 1 kg to 300 g.',
                'Change 1 kg into grams. 1 kg = 1000 g. Then divide 1000 and 300 by the same number.',
                '1000 : 300 = 10 : 3.',
                '10:3',
                ['1:300'],
            ),
            $single(
                false,
                'Same unit — metres and centimetres',
                'length',
                'A metre and a centimetre are different units. Change metres to centimetres first.',
                null,
                'Write the ratio of 2 m to 50 cm.',
                '1 m = 100 cm, so 2 m = 200 cm.',
                '200 : 50 = 4 : 1.',
                '4:1',
                ['2:50'],
            ),
            $single(
                false,
                'Same unit — hours and minutes',
                'time',
                'An hour and a minute are different units. Change hours to minutes first.',
                null,
                'Write the ratio of 1 hour to 15 minutes.',
                '1 hour = 60 minutes.',
                '60 : 15 = 4 : 1.',
                '4:1',
                ['1:15'],
            ),
            $single(
                false,
                'Same unit — rupees and paise',
                'price',
                'A rupee and a paisa are different units. Change rupees to paise first.',
                null,
                'Write the ratio of ₹2 to 50 paise.',
                '₹1 = 100 paise, so ₹2 = 200 paise.',
                '200 : 50 = 4 : 1.',
                '4:1',
                ['2:50'],
            ),
            [
                'demo' => false,
                'title' => 'Ten ratios',
                'answers' => [
                    'mode' => 'ratio',
                    'play' => [
                        'page_blurb' => 'Write each ratio in the simplest form. If the units differ, change both to the same unit first.',
                        'items' => $practice,
                    ],
                    'check' => [
                        'items' => $answers,
                        'same_unit' => $sameUnit,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array{story: string, hint: string, ratio: string, trap: ?string}>
     */
    private function practiceSums(): array
    {
        return [
            [
                'story' => 'A class has 16 boys and 24 girls. Boys to girls.',
                'hint' => '16 : 24 = 2 : 3.',
                'ratio' => '2:3',
                'trap' => null,
            ],
            [
                'story' => 'A pencil costs ₹25. A box of colours costs ₹40. Pencil to colours.',
                'hint' => '25 : 40 = 5 : 8.',
                'ratio' => '5:8',
                'trap' => null,
            ],
            [
                'story' => '9 kg of sugar and 12 kg of flour. Sugar to flour.',
                'hint' => '9 : 12 = 3 : 4.',
                'ratio' => '3:4',
                'trap' => null,
            ],
            [
                'story' => 'A rope is 35 cm. A pole is 50 cm. Rope to pole.',
                'hint' => '35 : 50 = 7 : 10.',
                'ratio' => '7:10',
                'trap' => null,
            ],
            [
                'story' => 'A song is 45 minutes. A match is 60 minutes. Song to match.',
                'hint' => '45 : 60 = 3 : 4.',
                'ratio' => '3:4',
                'trap' => null,
            ],
            [
                'story' => '2 kg of apples to 500 g of grapes.',
                'hint' => '2 kg = 2000 g. 2000 : 500 = 4 : 1.',
                'ratio' => '4:1',
                'trap' => '2:500',
            ],
            [
                'story' => '3 m of cloth to 75 cm of lace.',
                'hint' => '3 m = 300 cm. 300 : 75 = 4 : 1.',
                'ratio' => '4:1',
                'trap' => '3:75',
            ],
            [
                'story' => '2 hours to 30 minutes.',
                'hint' => '2 hours = 120 minutes. 120 : 30 = 4 : 1.',
                'ratio' => '4:1',
                'trap' => '2:30',
            ],
            [
                'story' => '₹5 to 50 paise.',
                'hint' => '₹5 = 500 paise. 500 : 50 = 10 : 1.',
                'ratio' => '10:1',
                'trap' => '5:50',
            ],
            [
                'story' => '4 kg of rice to 800 g of dal.',
                'hint' => '4 kg = 4000 g. 4000 : 800 = 5 : 1.',
                'ratio' => '5:1',
                'trap' => '4:800',
            ],
        ];
    }

    private function ratioHeadId($now): int
    {
        $head = DB::table('chapter_heads')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->first(function ($row) {
                $name = (string) $row->name;

                return preg_match('/ratio/i', $name) === 1
                    && preg_match('/rational/i', $name) !== 1;
            });

        if ($head) {
            return (int) $head->id;
        }

        $name = 'Ratio & Proportion';

        return (int) DB::table('chapter_heads')->insertGetId([
            'name' => $name,
            'branch' => ConceptBranch::guessFromName($name),
            'sort_order' => ((int) DB::table('chapter_heads')->max('sort_order')) + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'ratios-basics')->value('id');
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
