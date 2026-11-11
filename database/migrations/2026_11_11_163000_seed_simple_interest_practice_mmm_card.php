<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'banking-simple-interest-practice')->exists()) {
            return;
        }

        $now = now();
        $source = DB::table('mmm_cards')->where('slug', 'banking-simple-interest')->first();
        $headId = $source->chapter_head_id
            ?? DB::table('chapter_heads')->where('name', 'like', '%Banking%')->orderBy('id')->value('id');

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'banking-simple-interest-practice',
            'title' => 'Banking — Simple Interest practice',
            'heading' => 'Banking — Simple Interest — practice sums',
            'chapter_head_id' => $headId,
            'sub_heading' => 'Simple Interest',
            'kind' => 'simple_interest',
            'status' => 'published',
            'accent' => 'teal',
            'method_blurb' => 'For each sum: copy the outline into your notebook. Fill Given (P, R, T or SI), write To find, write the formula, calculate carefully, then punch only the final answer on the screen.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 41,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [];
        foreach ($this->exercises() as $index => $exercise) {
            $rows[] = [
                'mmm_card_id' => $cardId,
                'sort_order' => $index + 1,
                'difficulty' => $exercise['difficulty'],
                'is_demo' => false,
                'question_text' => $exercise['title'],
                'answers' => json_encode([
                    'mode' => 'simple_interest_practice',
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
        $prt = [
            ['label' => 'Principal', 'notation' => 'P'],
            ['label' => 'Rate of interest per annum', 'notation' => 'R'],
            ['label' => 'Time', 'notation' => 'T'],
        ];
        $psi = [
            ['label' => 'Principal', 'notation' => 'P'],
            ['label' => 'Simple interest', 'notation' => 'SI'],
            ['label' => 'Time', 'notation' => 'T'],
        ];
        $psr = [
            ['label' => 'Principal', 'notation' => 'P'],
            ['label' => 'Simple interest', 'notation' => 'SI'],
            ['label' => 'Rate of interest per annum', 'notation' => 'R'],
        ];
        $srt = [
            ['label' => 'Simple interest', 'notation' => 'SI'],
            ['label' => 'Rate of interest per annum', 'notation' => 'R'],
            ['label' => 'Time', 'notation' => 'T'],
        ];

        return [
            // 1 Find SI
            $this->sum(
                'medium',
                'Find SI — ₹7500 at 8% for 4 years',
                'Find the simple interest on ₹7500 at 8% per annum for 4 years.',
                $prt,
                'SI',
                'SI = PRT/100',
                'SI',
                '₹',
                '2400',
                'SI = (7500 × 8 × 4) / 100 = 2400.',
            ),
            // 2 Find Amount
            $this->sum(
                'medium',
                'Find amount — ₹4800 at 7% for 3 years',
                'A sum of ₹4800 is lent at 7% per annum for 3 years. Find the amount.',
                $prt,
                'A (Amount)',
                'SI = PRT/100, then A = P + SI',
                'Amount A',
                '₹',
                '5808',
                'SI = (4800 × 7 × 3) / 100 = 1008. A = 4800 + 1008 = 5808.',
            ),
            // 3 Find R
            $this->sum(
                'medium',
                'Find rate — SI ₹1620 on ₹9000 in 3 years',
                'The simple interest on ₹9000 for 3 years is ₹1620. Find the rate of interest per annum.',
                $psi,
                'R',
                'R = (SI × 100) / (P × T)',
                'Rate R',
                '%',
                '6',
                'R = (1620 × 100) / (9000 × 3) = 162000 / 27000 = 6.',
            ),
            // 4 Find T
            $this->sum(
                'medium',
                'Find time — SI ₹1800 on ₹6000 at 6%',
                'In how many years will ₹6000 yield ₹1800 as simple interest at 6% per annum?',
                $psr,
                'T',
                'T = (SI × 100) / (P × R)',
                'Time T',
                'years',
                '5',
                'T = (1800 × 100) / (6000 × 6) = 180000 / 36000 = 5.',
            ),
            // 5 Find P
            $this->sum(
                'hard',
                'Find principal — SI ₹960 at 8% for 3 years',
                'What principal will give ₹960 as simple interest in 3 years at 8% per annum?',
                $srt,
                'P',
                'P = (SI × 100) / (R × T)',
                'Principal P',
                '₹',
                '4000',
                'P = (960 × 100) / (8 × 3) = 96000 / 24 = 4000.',
            ),
            // 6 Find SI harder
            $this->sum(
                'hard',
                'Find SI — ₹12500 at 8% for 2 years',
                'Find the simple interest on ₹12500 for 2 years at 8% per annum.',
                $prt,
                'SI',
                'SI = PRT/100',
                'SI',
                '₹',
                '2000',
                'SI = (12500 × 8 × 2) / 100 = 2000.',
            ),
            // 7 Find Amount harder
            $this->sum(
                'hard',
                'Find amount — ₹9800 at 5% for 4 years',
                'Sohan deposits ₹9800 at 5% per annum simple interest for 4 years. What amount will he get back?',
                $prt,
                'A (Amount)',
                'SI = PRT/100, then A = P + SI',
                'Amount A',
                '₹',
                '11760',
                'SI = (9800 × 5 × 4) / 100 = 1960. A = 9800 + 1960 = 11760.',
            ),
            // 8 Find R harder
            $this->sum(
                'hard',
                'Find rate — SI ₹2250 on ₹7500 in 5 years',
                '₹7500 earns ₹2250 as simple interest in 5 years. Find the rate percent per annum.',
                $psi,
                'R',
                'R = (SI × 100) / (P × T)',
                'Rate R',
                '%',
                '6',
                'R = (2250 × 100) / (7500 × 5) = 225000 / 37500 = 6.',
            ),
            // 9 Find T harder
            $this->sum(
                'hard',
                'Find time — SI ₹2100 on ₹8400 at 5%',
                'The simple interest on ₹8400 at 5% per annum is ₹2100. Find the time in years.',
                $psr,
                'T',
                'T = (SI × 100) / (P × R)',
                'Time T',
                'years',
                '5',
                'T = (2100 × 100) / (8400 × 5) = 210000 / 42000 = 5.',
            ),
            // 10 Find Amount / mixed wording
            $this->sum(
                'hard',
                'Find amount — ₹5600 at 9% for 5 years',
                'A shopkeeper borrows ₹5600 at 9% per annum simple interest for 5 years. What total amount must he repay?',
                $prt,
                'A (Amount)',
                'SI = PRT/100, then A = P + SI',
                'Amount A',
                '₹',
                '8120',
                'SI = (5600 × 9 × 5) / 100 = 2520. A = 5600 + 2520 = 8120.',
            ),
        ];
    }

    /**
     * @param  list<array{label: string, notation: string}>  $outlineRows
     * @return array<string, mixed>
     */
    private function sum(
        string $difficulty,
        string $title,
        string $story,
        array $outlineRows,
        string $findLabel,
        string $formulaOutline,
        string $answerLabel,
        string $answerUnit,
        string $answer,
        string $hint,
    ): array {
        return [
            'difficulty' => $difficulty,
            'title' => $title,
            'play' => [
                'story' => $story,
                'blurb' => 'Copy the outline into your notebook. Fill Given, write To find, write the formula, calculate, then punch only the final answer.',
                'outline_rows' => $outlineRows,
                'find_label' => $findLabel,
                'formula_outline' => $formulaOutline,
                'answer_label' => $answerLabel,
                'answer_unit' => $answerUnit,
                'hint' => $hint,
            ],
            'check' => [
                'answer' => $answer,
                'wrong' => 'Check your Given values and formula in the copy, then calculate again.',
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'banking-simple-interest-practice')->value('id');
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
