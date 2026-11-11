<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'banking-simple-interest-practice')->value('id');
        if (! $cardId) {
            return;
        }

        if (DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 11)->exists()) {
            return;
        }

        $now = now();
        $max = (int) DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->max('sort_order');
        $rows = [];
        foreach ($this->exercises() as $index => $exercise) {
            $rows[] = [
                'mmm_card_id' => $cardId,
                'sort_order' => $max + $index + 1,
                'difficulty' => 'hard',
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
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function exercises(): array
    {
        return [
            // 11 Doubles → find R
            $this->sum(
                'Doubles in 5 years — find rate',
                'A sum of money doubles itself in 5 years at simple interest. Find the rate of interest per annum.',
                [
                    ['label' => 'Time', 'notation' => 'T'],
                    ['label' => 'Relation (doubles)', 'notation' => 'SI = P'],
                ],
                'R',
                'SI = P ⇒ PRT/100 = P ⇒ R = 100/T',
                'Rate R',
                '%',
                '20',
                'Money doubles ⇒ SI = P. So PRT/100 = P ⇒ R = 100/T = 100/5 = 20.',
            ),
            // 12 Triples → find R
            $this->sum(
                'Triples in 8 years — find rate',
                'A sum of money triples itself in 8 years at simple interest. Find the rate of interest per annum.',
                [
                    ['label' => 'Time', 'notation' => 'T'],
                    ['label' => 'Relation (triples)', 'notation' => 'SI = 2P'],
                ],
                'R',
                'SI = 2P ⇒ PRT/100 = 2P ⇒ R = 200/T',
                'Rate R',
                '%',
                '25',
                'Money triples ⇒ SI = 2P. So PRT/100 = 2P ⇒ R = 200/T = 200/8 = 25.',
            ),
            // 13 Doubles → find T
            $this->sum(
                'Doubles at 10% — find time',
                'In what time will a sum of money double itself at 10% per annum simple interest?',
                [
                    ['label' => 'Rate of interest per annum', 'notation' => 'R'],
                    ['label' => 'Relation (doubles)', 'notation' => 'SI = P'],
                ],
                'T',
                'SI = P ⇒ PRT/100 = P ⇒ T = 100/R',
                'Time T',
                'years',
                '10',
                'Money doubles ⇒ SI = P. So PRT/100 = P ⇒ T = 100/R = 100/10 = 10 years.',
            ),
            // 14 Triples → find T
            $this->sum(
                'Triples at 8% — find time',
                'In how many years will a sum of money become three times itself at 8% per annum simple interest?',
                [
                    ['label' => 'Rate of interest per annum', 'notation' => 'R'],
                    ['label' => 'Relation (triples)', 'notation' => 'SI = 2P'],
                ],
                'T',
                'SI = 2P ⇒ PRT/100 = 2P ⇒ T = 200/R',
                'Time T',
                'years',
                '25',
                'Becomes 3 times ⇒ SI = 2P. So PRT/100 = 2P ⇒ T = 200/R = 200/8 = 25 years.',
            ),
            // 15 Becomes 4 times → find R
            $this->sum(
                'Becomes 4 times in 12 years — find rate',
                'A sum of money becomes 4 times itself in 12 years at simple interest. Find the rate of interest per annum.',
                [
                    ['label' => 'Time', 'notation' => 'T'],
                    ['label' => 'Relation (4 times)', 'notation' => 'SI = 3P'],
                ],
                'R',
                'SI = 3P ⇒ PRT/100 = 3P ⇒ R = 300/T',
                'Rate R',
                '%',
                '25',
                'Becomes 4 times ⇒ SI = 3P. So PRT/100 = 3P ⇒ R = 300/T = 300/12 = 25.',
            ),
        ];
    }

    /**
     * @param  list<array{label: string, notation: string}>  $outlineRows
     * @return array<string, mixed>
     */
    private function sum(
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
            'title' => $title,
            'play' => [
                'story' => $story,
                'blurb' => 'Copy the outline into your notebook. Use the relation (doubles ⇒ SI = P, triples ⇒ SI = 2P). Write To find, formula, calculate, then punch the answer.',
                'outline_rows' => $outlineRows,
                'find_label' => $findLabel,
                'formula_outline' => $formulaOutline,
                'answer_label' => $answerLabel,
                'answer_unit' => $answerUnit,
                'hint' => $hint,
            ],
            'check' => [
                'answer' => $answer,
                'wrong' => 'When money becomes n times, SI = (n − 1)P. Then use SI = PRT/100.',
            ],
        ];
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'banking-simple-interest-practice')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', '>=', 11)
            ->delete();
    }
};
