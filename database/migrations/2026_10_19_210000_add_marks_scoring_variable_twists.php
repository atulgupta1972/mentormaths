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
        $exercises = [
            [
                'sort_order' => 8,
                'difficulty' => 'variable',
                'question_text' => 'In a quiz, each correct answer gives +x marks and each wrong answer gives −1 mark. A student attempted 12 questions and got 8 correct. The final score is 20. Find x.',
                'answers' => [
                    'mode' => 'variable',
                    'variable_tip' => 'Here the mark for a correct answer is unknown — call it x. Wrong is −1. Wrong count = 12 − 8 = 4. Fill the table with x, then find x from the score.',
                    'x_prompt' => 'Marks for each correct answer (x)',
                    'correct' => [
                        'marks' => ['x', 'X'],
                        'count' => '8',
                        'total' => ['8x', '8X'],
                    ],
                    'wrong' => [
                        'marks' => '-1',
                        'count' => '4',
                        'total' => '-4',
                    ],
                    'grand_total' => '20',
                    'x_value' => '3',
                ],
            ],
            [
                'sort_order' => 9,
                'difficulty' => 'variable',
                'question_text' => 'A wrong answer gives −x marks. A correct answer gives 2 marks more than that (so + (x + 2)). In 10 questions a student got 6 correct and 4 wrong, and scored 20. Find x.',
                'answers' => [
                    'mode' => 'variable',
                    'variable_tip' => 'Let x = marks lost for a wrong answer (enter −x in Marks for Wrong). Correct marks = x + 2. Totals become 6(x+2) and −4x. Find x.',
                    'x_prompt' => 'Value of x (wrong-mark size)',
                    'correct' => [
                        'marks' => ['x+2', '2+x'],
                        'count' => '6',
                        'total' => ['6(x+2)', '6x+12', '(x+2)*6'],
                    ],
                    'wrong' => [
                        'marks' => ['-x', '−x'],
                        'count' => '4',
                        'total' => ['-4x', '−4x'],
                    ],
                    'grand_total' => '20',
                    'x_value' => '4',
                ],
            ],
            [
                'sort_order' => 10,
                'difficulty' => 'variable',
                'question_text' => 'Each correct answer gives +x marks. Each wrong answer gives 2 marks less than that as a penalty, so wrong = −(x − 2). A student attempted 10 questions, got 7 correct, and scored 26. Find x.',
                'answers' => [
                    'mode' => 'variable',
                    'variable_tip' => 'Correct marks = x. Wrong marks = −(x − 2), which is the same as 2 − x. Wrong count = 3. Fill Totals (7x and 3(2−x)), then find x.',
                    'x_prompt' => 'Marks for each correct answer (x)',
                    'correct' => [
                        'marks' => ['x', 'X'],
                        'count' => '7',
                        'total' => ['7x', '7X'],
                    ],
                    'wrong' => [
                        'marks' => ['2-x', '-(x-2)', '-x+2'],
                        'count' => '3',
                        'total' => ['3(2-x)', '6-3x', '-3(x-2)', '3*(2-x)'],
                    ],
                    'grand_total' => '26',
                    'x_value' => '5',
                ],
            ],
            [
                'sort_order' => 11,
                'difficulty' => 'variable',
                'question_text' => 'Correct answers give +4 marks and wrong answers give −2. A student attempted 15 questions and scored 30 marks. Let x = number of correct answers. How many were correct?',
                'answers' => [
                    'mode' => 'variable',
                    'variable_tip' => 'Now x is the count of correct answers (not the mark). Wrong count = 15 − x. Marks are numbers: +4 and −2. Fill Totals 4x and −2(15−x), then find x.',
                    'x_prompt' => 'How many correct? (value of x)',
                    'correct' => [
                        'marks' => '4',
                        'count' => ['x', 'X'],
                        'total' => ['4x', '4X'],
                    ],
                    'wrong' => [
                        'marks' => '-2',
                        'count' => ['15-x', '15 - x'],
                        'total' => ['-2(15-x)', '-30+2x', '2x-30', '-2*(15-x)'],
                    ],
                    'grand_total' => '30',
                    'x_value' => '10',
                ],
            ],
        ];

        foreach ($exercises as $exercise) {
            $exists = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $exercise['sort_order'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('mmm_card_exercises')->insert([
                'mmm_card_id' => $cardId,
                'sort_order' => $exercise['sort_order'],
                'difficulty' => $exercise['difficulty'],
                'is_demo' => false,
                'question_text' => $exercise['question_text'],
                'answers' => json_encode($exercise['answers'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'marks-scoring-table')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->whereIn('sort_order', [8, 9, 10, 11])
            ->delete();
    }
};
