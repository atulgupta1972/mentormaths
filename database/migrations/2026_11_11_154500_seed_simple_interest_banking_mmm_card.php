<?php

use App\Support\ConceptBranch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'banking-simple-interest')->exists()) {
            return;
        }

        $now = now();
        $headId = $this->bankingHeadId($now);

        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'banking-simple-interest',
            'title' => 'Banking — Simple Interest',
            'heading' => 'Banking — Simple Interest',
            'chapter_head_id' => $headId,
            'sub_heading' => 'Simple Interest',
            'kind' => 'simple_interest',
            'status' => 'published',
            'accent' => 'teal',
            'method_blurb' => 'Read the sum. Make a Given table with Notation and Value for P, R, T (and SI if given). Write To find. Write the formula. Then calculate step by step. SI = PRT/100. Amount A = P + SI. To find R use R = (SI × 100)/(P × T). To find T use T = (SI × 100)/(P × R).',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 40,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rows = [];
        foreach ($this->exercises() as $index => $exercise) {
            $rows[] = [
                'mmm_card_id' => $cardId,
                'sort_order' => $index + 1,
                'difficulty' => $exercise['demo'] ? 'demo' : $exercise['difficulty'],
                'is_demo' => $exercise['demo'],
                'question_text' => $exercise['title'],
                'answers' => json_encode([
                    'mode' => 'simple_interest',
                    'play' => $exercise['play'],
                    'check' => $exercise['check'],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('mmm_card_exercises')->insert($rows);

        if (DB::getSchemaBuilder()->hasTable('mmm_card_grade_level') && DB::getSchemaBuilder()->hasTable('grade_levels')) {
            $gradeIds = DB::table('grade_levels')
                ->get(['id', 'name'])
                ->filter(fn ($row) => preg_match('/\b(?:class|grade)\s*([78])\b/i', (string) $row->name))
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
            // 1 Demo — find SI
            $this->sum(
                demo: true,
                difficulty: 'easy',
                title: 'Demo — find simple interest',
                story: 'Ram deposits ₹1000 in a bank at 5% per annum for 2 years. Find the simple interest.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '1000', 'hint' => 'The money deposited is ₹1000.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '5', 'hint' => 'Rate is 5% per year. Write 5.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '2', 'hint' => 'Time is 2 years. Write 2.'],
                ],
                find: 'SI',
                findHint: 'The question asks for simple interest.',
                formulas: ['SI=PRT/100', 'SI=(PRT)/100', 'SI=P*R*T/100'],
                formulaHint: 'SI = PRT / 100',
                steps: [
                    ['key' => 'SI', 'label' => 'Simple interest SI', 'value' => '100', 'unit' => '₹', 'hint' => 'SI = (1000 × 5 × 2) / 100 = 100'],
                ],
            ),
            // 2 Demo — find Amount (matches notebook template)
            $this->sum(
                demo: true,
                difficulty: 'easy',
                title: 'Demo — find amount',
                story: 'A sum of ₹100 is lent at 5% per annum for 3 years. Find the amount.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '100', 'hint' => 'Principal P = 100.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '5', 'hint' => 'Rate R = 5%.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '3', 'hint' => 'Time T = 3 years.'],
                ],
                find: 'A',
                findHint: 'Amount means the total money returned: A = P + SI.',
                formulas: ['A=P+SI', 'SI=PRT/100', 'A=P+PRT/100'],
                formulaHint: 'First SI = PRT/100. Then A = P + SI.',
                steps: [
                    ['key' => 'SI', 'label' => 'Simple interest SI', 'value' => '15', 'unit' => '₹', 'hint' => 'SI = (100 × 5 × 3) / 100 = 15'],
                    ['key' => 'A', 'label' => 'Amount A', 'value' => '115', 'unit' => '₹', 'hint' => 'A = P + SI = 100 + 15 = 115'],
                ],
            ),
            // 3–4 Find SI
            $this->sum(
                demo: false,
                difficulty: 'easy',
                title: 'Find SI — ₹2500 at 8% for 3 years',
                story: 'Find the simple interest on ₹2500 at 8% per annum for 3 years.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '2500', 'hint' => 'P = 2500.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '8', 'hint' => 'R = 8.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '3', 'hint' => 'T = 3.'],
                ],
                find: 'SI',
                findHint: 'Find simple interest.',
                formulas: ['SI=PRT/100', 'SI=(PRT)/100', 'SI=P*R*T/100'],
                formulaHint: 'SI = PRT / 100',
                steps: [
                    ['key' => 'SI', 'label' => 'Simple interest SI', 'value' => '600', 'unit' => '₹', 'hint' => 'SI = (2500 × 8 × 3) / 100 = 600'],
                ],
            ),
            $this->sum(
                demo: false,
                difficulty: 'easy',
                title: 'Find SI — ₹800 at 6% for 2 years',
                story: 'A bank pays 6% per annum simple interest. Meera deposits ₹800 for 2 years. Find the simple interest.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '800', 'hint' => 'P = 800.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '6', 'hint' => 'R = 6.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '2', 'hint' => 'T = 2.'],
                ],
                find: 'SI',
                findHint: 'Find simple interest.',
                formulas: ['SI=PRT/100', 'SI=(PRT)/100', 'SI=P*R*T/100'],
                formulaHint: 'SI = PRT / 100',
                steps: [
                    ['key' => 'SI', 'label' => 'Simple interest SI', 'value' => '96', 'unit' => '₹', 'hint' => 'SI = (800 × 6 × 2) / 100 = 96'],
                ],
            ),
            // 5–6 Find Amount
            $this->sum(
                demo: false,
                difficulty: 'easy',
                title: 'Find amount — ₹1500 at 4% for 5 years',
                story: 'Find the amount on ₹1500 at 4% per annum for 5 years.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '1500', 'hint' => 'P = 1500.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '4', 'hint' => 'R = 4.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '5', 'hint' => 'T = 5.'],
                ],
                find: 'A',
                findHint: 'Find the amount A = P + SI.',
                formulas: ['A=P+SI', 'SI=PRT/100', 'A=P+PRT/100'],
                formulaHint: 'SI = PRT/100, then A = P + SI.',
                steps: [
                    ['key' => 'SI', 'label' => 'Simple interest SI', 'value' => '300', 'unit' => '₹', 'hint' => 'SI = (1500 × 4 × 5) / 100 = 300'],
                    ['key' => 'A', 'label' => 'Amount A', 'value' => '1800', 'unit' => '₹', 'hint' => 'A = 1500 + 300 = 1800'],
                ],
            ),
            $this->sum(
                demo: false,
                difficulty: 'medium',
                title: 'Find amount — ₹2000 at 10% for 2 years',
                story: 'Sita borrows ₹2000 at 10% per annum simple interest for 2 years. What amount must she return?',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '2000', 'hint' => 'P = 2000.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '10', 'hint' => 'R = 10.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '2', 'hint' => 'T = 2.'],
                ],
                find: 'A',
                findHint: 'Amount to return = P + SI.',
                formulas: ['A=P+SI', 'SI=PRT/100', 'A=P+PRT/100'],
                formulaHint: 'SI = PRT/100, then A = P + SI.',
                steps: [
                    ['key' => 'SI', 'label' => 'Simple interest SI', 'value' => '400', 'unit' => '₹', 'hint' => 'SI = (2000 × 10 × 2) / 100 = 400'],
                    ['key' => 'A', 'label' => 'Amount A', 'value' => '2400', 'unit' => '₹', 'hint' => 'A = 2000 + 400 = 2400'],
                ],
            ),
            // 7–8 Find R
            $this->sum(
                demo: false,
                difficulty: 'medium',
                title: 'Find rate — SI ₹750 on ₹5000 in 3 years',
                story: 'The simple interest on ₹5000 for 3 years is ₹750. Find the rate of interest per annum.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '5000', 'hint' => 'P = 5000.'],
                    ['key' => 'SI', 'label' => 'Simple interest', 'notation' => 'SI', 'value' => '750', 'hint' => 'SI = 750.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '3', 'hint' => 'T = 3.'],
                ],
                find: 'R',
                findHint: 'Find the rate R.',
                formulas: ['R=(SI*100)/(P*T)', 'R=SI*100/(P*T)', 'R=(100*SI)/(P*T)'],
                formulaHint: 'R = (SI × 100) / (P × T)',
                steps: [
                    ['key' => 'R', 'label' => 'Rate R', 'value' => '5', 'unit' => '%', 'hint' => 'R = (750 × 100) / (5000 × 3) = 5'],
                ],
            ),
            $this->sum(
                demo: false,
                difficulty: 'medium',
                title: 'Find rate — SI ₹240 on ₹2000 in 3 years',
                story: 'A sum of ₹2000 yields ₹240 as simple interest in 3 years. Find the rate percent per annum.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '2000', 'hint' => 'P = 2000.'],
                    ['key' => 'SI', 'label' => 'Simple interest', 'notation' => 'SI', 'value' => '240', 'hint' => 'SI = 240.'],
                    ['key' => 'T', 'label' => 'Time', 'notation' => 'T', 'value' => '3', 'hint' => 'T = 3.'],
                ],
                find: 'R',
                findHint: 'Find the rate R.',
                formulas: ['R=(SI*100)/(P*T)', 'R=SI*100/(P*T)', 'R=(100*SI)/(P*T)'],
                formulaHint: 'R = (SI × 100) / (P × T)',
                steps: [
                    ['key' => 'R', 'label' => 'Rate R', 'value' => '4', 'unit' => '%', 'hint' => 'R = (240 × 100) / (2000 × 3) = 4'],
                ],
            ),
            // 9–10 Find T
            $this->sum(
                demo: false,
                difficulty: 'medium',
                title: 'Find time — SI ₹800 on ₹4000 at 5%',
                story: 'In how many years will ₹4000 yield ₹800 as simple interest at 5% per annum?',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '4000', 'hint' => 'P = 4000.'],
                    ['key' => 'SI', 'label' => 'Simple interest', 'notation' => 'SI', 'value' => '800', 'hint' => 'SI = 800.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '5', 'hint' => 'R = 5.'],
                ],
                find: 'T',
                findHint: 'Find the time T in years.',
                formulas: ['T=(SI*100)/(P*R)', 'T=SI*100/(P*R)', 'T=(100*SI)/(P*R)'],
                formulaHint: 'T = (SI × 100) / (P × R)',
                steps: [
                    ['key' => 'T', 'label' => 'Time T', 'value' => '4', 'unit' => 'years', 'hint' => 'T = (800 × 100) / (4000 × 5) = 4'],
                ],
            ),
            $this->sum(
                demo: false,
                difficulty: 'medium',
                title: 'Find time — SI ₹225 on ₹1500 at 5%',
                story: 'The simple interest on ₹1500 at 5% per annum is ₹225. Find the time in years.',
                given: [
                    ['key' => 'P', 'label' => 'Principal', 'notation' => 'P', 'value' => '1500', 'hint' => 'P = 1500.'],
                    ['key' => 'SI', 'label' => 'Simple interest', 'notation' => 'SI', 'value' => '225', 'hint' => 'SI = 225.'],
                    ['key' => 'R', 'label' => 'Rate of interest per annum', 'notation' => 'R', 'value' => '5', 'hint' => 'R = 5.'],
                ],
                find: 'T',
                findHint: 'Find the time T.',
                formulas: ['T=(SI*100)/(P*R)', 'T=SI*100/(P*R)', 'T=(100*SI)/(P*R)'],
                formulaHint: 'T = (SI × 100) / (P × R)',
                steps: [
                    ['key' => 'T', 'label' => 'Time T', 'value' => '3', 'unit' => 'years', 'hint' => 'T = (225 × 100) / (1500 × 5) = 3'],
                ],
            ),
        ];
    }

    /**
     * @param  list<array{key: string, label: string, notation: string, value: string, hint: string, placeholder?: string}>  $given
     * @param  list<string>  $formulas
     * @param  list<array{key: string, label: string, value: string, unit?: string, hint: string}>  $steps
     * @return array<string, mixed>
     */
    private function sum(
        bool $demo,
        string $difficulty,
        string $title,
        string $story,
        array $given,
        string $find,
        string $findHint,
        array $formulas,
        string $formulaHint,
        array $steps,
    ): array {
        $givenRows = [];
        $givenCheck = [];
        $hints = [];
        $wrong = [];
        foreach ($given as $row) {
            $givenRows[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'notation' => $row['notation'],
                'placeholder' => $row['placeholder'] ?? '',
            ];
            $givenCheck[$row['key']] = $row['value'];
            $hints['given.'.$row['key']] = $row['hint'];
            $wrong['given.'.$row['key']] = $row['hint'];
        }

        $calcSteps = [];
        $stepCheck = [];
        foreach ($steps as $step) {
            $calcSteps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'unit' => $step['unit'] ?? '',
                'hint' => $step['hint'],
            ];
            $stepCheck[$step['key']] = $step['value'];
            $hints['step.'.$step['key']] = $step['hint'];
            $wrong['step.'.$step['key']] = $step['hint'];
        }

        $hints['to_find'] = $findHint;
        $hints['formula'] = $formulaHint;
        $wrong['to_find'] = $findHint;
        $wrong['formula'] = $formulaHint;

        return [
            'demo' => $demo,
            'difficulty' => $difficulty,
            'title' => $title,
            'play' => [
                'story' => $story,
                'given_rows' => $givenRows,
                'table_cue' => 'Read the sum. Write each given number next to its letter. Do not write units in the value box.',
                'to_find_cue' => 'What must you find? Write SI, A (Amount), R, or T.',
                'to_find_hint' => $findHint,
                'formula_cue' => 'Write the formula that matches what you must find.',
                'formula_hint' => $formulaHint,
                'calc_cue' => 'Substitute and calculate each step. Number only.',
                'calc_steps' => $calcSteps,
                'hints' => $hints,
            ],
            'check' => [
                'given' => $givenCheck,
                'to_find' => $find,
                'formula' => $formulas,
                'steps' => $stepCheck,
                'wrong' => $wrong,
            ],
        ];
    }

    private function bankingHeadId($now): int
    {
        $head = DB::table('chapter_heads')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->first(function ($row) {
                $name = (string) $row->name;

                return preg_match('/banking|simple\s*interest|commercial\s*math/i', $name) === 1;
            });

        if ($head) {
            return (int) $head->id;
        }

        $name = 'Banking';

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
        $cardId = DB::table('mmm_cards')->where('slug', 'banking-simple-interest')->value('id');
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
