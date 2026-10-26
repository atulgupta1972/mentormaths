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
        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', '>', 1)
            ->delete();

        $items = [
            [
                'key' => 'q1',
                'label' => 'Score =',
                'answer' => '33',
                'prompt' => 'A quiz gives +5 marks for each correct answer and −3 for each wrong answer. A student got 9 correct and 4 wrong. What is the score?',
                'hint' => '5 × 9 and −3 × 4, then add the two totals.',
            ],
            [
                'key' => 'q2',
                'label' => 'Score =',
                'answer' => '19',
                'prompt' => 'Each correct answer gives +3 marks and each wrong answer gives −2. A student attempted 18 questions and got 11 correct. What is the score?',
                'hint' => 'Wrong = 18 − 11 = 7. Then 3 × 11 and −2 × 7.',
            ],
            [
                'key' => 'q3',
                'label' => 'Score =',
                'answer' => '-10',
                'prompt' => 'Correct answers give +2 marks and wrong answers give −3. A student got 4 correct and 6 wrong. What is the score?',
                'hint' => '2 × 4 and −3 × 6. The score can be negative.',
            ],
            [
                'key' => 'q4',
                'label' => 'Score =',
                'answer' => '8',
                'prompt' => 'A paper has 10 questions. Kabir got 5 correct and attempted 7 in all. Correct answers give +2, wrong answers give −1, and not attempted give 0. What is his score?',
                'hint' => 'Wrong = 7 − 5 = 2. Not attempted scores 0, so it does not change the total.',
            ],
            [
                'key' => 'q5',
                'label' => 'Score =',
                'answer' => '29',
                'prompt' => 'A paper has 20 questions. Arun got 11 correct, 6 wrong, and left 3 not attempted. Marks: +4 for correct, −2 for wrong, and −1 for not attempted. What is his score?',
                'hint' => '4 × 11, −2 × 6, and −1 × 3.',
            ],
            [
                'key' => 'q6',
                'label' => 'x =',
                'answer' => '15',
                'prompt' => 'A test gives +2 marks for each correct answer and −1 for each wrong answer. A student attempted 20 questions and scored 25. Let x = number of correct answers. Find x.',
                'hint' => 'Wrong = 20 − x. Then 2x − (20 − x) = 25.',
            ],
            [
                'key' => 'q7',
                'label' => 'x =',
                'answer' => '3',
                'prompt' => 'Each correct answer gives +x marks and each wrong answer gives −1. A student attempted 12 questions and got 8 correct. The score is 20. Find x.',
                'hint' => 'Wrong = 4. Then 8x − 4 = 20.',
            ],
            [
                'key' => 'q8',
                'label' => 'x =',
                'answer' => '4',
                'prompt' => 'A wrong answer gives −x marks. A correct answer gives 2 marks more than that, so +(x + 2). In 10 questions a student got 6 correct and 4 wrong, and scored 20. Find x.',
                'hint' => '6(x + 2) − 4x = 20.',
            ],
            [
                'key' => 'q9',
                'label' => 'x =',
                'answer' => '4',
                'prompt' => 'Correct answers give +3 marks and wrong answers give −2. A student attempted 16 questions and scored 28. Let x = number of wrong answers. Find x.',
                'hint' => 'Correct = 16 − x. Then 3(16 − x) − 2x = 28.',
            ],
            [
                'key' => 'q10',
                'label' => 'x =',
                'answer' => '3',
                'prompt' => 'Each correct answer gives +5 marks. Each wrong answer takes away x marks. A student got 8 correct and 5 wrong, and scored 25. Find x.',
                'hint' => '40 − 5x = 25.',
            ],
        ];

        $check = [];
        $playItems = [];
        foreach ($items as $item) {
            $check[$item['key']] = $item['answer'];
            $playItems[] = [
                'key' => $item['key'],
                'prompt' => $item['prompt'],
                'label' => $item['label'],
                'hint' => $item['hint'],
            ];
        }

        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 2,
            'difficulty' => 'practice',
            'is_demo' => false,
            'question_text' => 'Ten sums. Work each one in your copy, then fill only the answer.',
            'answers' => json_encode([
                'mode' => 'fill_page',
                'variable_tip' => 'Make the marks table in your copy if you need it. Type only the score, or the value of x.',
                'wrong_message' => 'Not yet. Make the table in your copy, then fill the score or x.',
                'check' => $check,
                'play' => [
                    'page_blurb' => 'Ten marks sums, medium to hard.',
                    'items' => $playItems,
                ],
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'marks-scoring-table')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 2)
            ->where('difficulty', 'practice')
            ->delete();
    }
};
