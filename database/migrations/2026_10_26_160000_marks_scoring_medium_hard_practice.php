<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'marks-scoring-table')->value('id');
        if (! $cardId) {
            return;
        }

        $now = now();
        $threeRows = [
            'columns' => [
                ['key' => 'label', 'label' => 'Answer', 'fixed' => true],
                ['key' => 'marks', 'label' => 'Marks'],
                ['key' => 'count', 'label' => 'No. of Ques'],
                ['key' => 'total', 'label' => 'Total'],
            ],
            'rows' => [
                ['key' => 'correct', 'label' => 'Correct'],
                ['key' => 'wrong', 'label' => 'Wrong'],
                ['key' => 'blank', 'label' => 'Not attempted'],
            ],
            'show_grand_total' => true,
            'grand_total_label' => 'Total marks',
        ];

        $exercises = [
            [
                'sort_order' => 2,
                'difficulty' => 'medium',
                'question_text' => 'A quiz gives +5 marks for each correct answer and −3 for each wrong answer. A student got 9 correct and 4 wrong. Fill the table and find the score.',
                'answers' => [
                    'correct' => ['marks' => '5', 'count' => '9', 'total' => '45'],
                    'wrong' => ['marks' => '-3', 'count' => '4', 'total' => '-12'],
                    'grand_total' => '33',
                ],
            ],
            [
                'sort_order' => 3,
                'difficulty' => 'medium',
                'question_text' => 'Each correct answer gives +3 marks and each wrong answer gives −2. A student attempted 18 questions and got 11 correct. How many were wrong, and what is the score?',
                'answers' => [
                    'correct' => ['marks' => '3', 'count' => '11', 'total' => '33'],
                    'wrong' => ['marks' => '-2', 'count' => '7', 'total' => '-14'],
                    'grand_total' => '19',
                ],
            ],
            [
                'sort_order' => 4,
                'difficulty' => 'medium',
                'question_text' => 'Correct answers give +2 marks and wrong answers give −3. A student got 4 correct and 6 wrong. Fill the table. The score can come out negative.',
                'answers' => [
                    'correct' => ['marks' => '2', 'count' => '4', 'total' => '8'],
                    'wrong' => ['marks' => '-3', 'count' => '6', 'total' => '-18'],
                    'grand_total' => '-10',
                ],
            ],
            [
                'sort_order' => 6,
                'difficulty' => 'hard',
                'question_text' => 'A paper has 20 questions. Arun got 11 correct, 6 wrong, and left 3 not attempted. Marks: +4 for correct, −2 for wrong, and −1 for not attempted. Find his score.',
                'answers' => [
                    'table_schema' => $threeRows,
                    'correct' => ['marks' => '4', 'count' => '11', 'total' => '44'],
                    'wrong' => ['marks' => '-2', 'count' => '6', 'total' => '-12'],
                    'blank' => ['marks' => '-1', 'count' => '3', 'total' => '-3'],
                    'grand_total' => '29',
                ],
            ],
            [
                'sort_order' => 10,
                'difficulty' => 'hard',
                'question_text' => 'Correct answers give +3 marks and wrong answers give −2. A student attempted 16 questions and scored 28. Let x = number of wrong answers. How many were wrong?',
                'answers' => [
                    'mode' => 'variable',
                    'variable_tip' => 'x is the wrong count, not the mark. Correct count = 16 − x. Totals are 3(16 − x) and −2x. Then find x.',
                    'x_prompt' => 'How many wrong? (value of x)',
                    'correct' => [
                        'marks' => '3',
                        'count' => ['16-x', '16 - x'],
                        'total' => ['3(16-x)', '48-3x', '3*(16-x)', '-3x+48'],
                    ],
                    'wrong' => [
                        'marks' => '-2',
                        'count' => ['x', 'X'],
                        'total' => ['-2x', '−2x'],
                    ],
                    'grand_total' => '28',
                    'x_value' => '4',
                ],
            ],
            [
                'sort_order' => 11,
                'difficulty' => 'hard',
                'question_text' => 'Each correct answer gives +5 marks. Each wrong answer takes away x marks. A student got 8 correct and 5 wrong, and scored 25. Find x.',
                'answers' => [
                    'mode' => 'variable',
                    'variable_tip' => 'x is how many marks one wrong answer takes away, so the Wrong marks cell is −x. Correct marks stay 5. Total is 40 − 5x.',
                    'x_prompt' => 'Marks taken away for one wrong answer (x)',
                    'correct' => [
                        'marks' => '5',
                        'count' => '8',
                        'total' => '40',
                    ],
                    'wrong' => [
                        'marks' => ['-x', '−x'],
                        'count' => '5',
                        'total' => ['-5x', '−5x'],
                    ],
                    'grand_total' => '25',
                    'x_value' => '3',
                ],
            ],
        ];

        foreach ($exercises as $exercise) {
            $payload = [
                'difficulty' => $exercise['difficulty'],
                'is_demo' => false,
                'question_text' => $exercise['question_text'],
                'answers' => json_encode($exercise['answers'], JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ];

            $updated = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $exercise['sort_order'])
                ->update($payload);

            if ($updated === 0) {
                DB::table('mmm_card_exercises')->insert($payload + [
                    'mmm_card_id' => $cardId,
                    'sort_order' => $exercise['sort_order'],
                    'created_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Content refresh of existing sums. Leaving the new questions in place is safe.
    }
};
