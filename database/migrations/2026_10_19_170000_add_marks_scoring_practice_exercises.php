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
                'sort_order' => 2,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'A test gives +3 marks for each correct answer and −1 for each wrong answer. Riya got 6 correct and 3 wrong. How many marks does she get?',
                'answers' => [
                    'correct' => ['marks' => '3', 'count' => '6', 'total' => '18'],
                    'wrong' => ['marks' => '-1', 'count' => '3', 'total' => '-3'],
                    'grand_total' => '15',
                ],
            ],
            [
                'sort_order' => 3,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Each correct answer gives +2 marks and each wrong answer gives −1. Aman attempted 12 sums and got 9 of them correct. Find his marks. (Hint: first find how many were wrong.)',
                'answers' => [
                    'correct' => ['marks' => '2', 'count' => '9', 'total' => '18'],
                    'wrong' => ['marks' => '-1', 'count' => '3', 'total' => '-3'],
                    'grand_total' => '15',
                ],
            ],
            [
                'sort_order' => 4,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'In a quiz of 10 questions, Meera got 7 correct and left none blank. Scoring is +5 for correct and −2 for wrong. What is her score?',
                'answers' => [
                    'correct' => ['marks' => '5', 'count' => '7', 'total' => '35'],
                    'wrong' => ['marks' => '-2', 'count' => '3', 'total' => '-6'],
                    'grand_total' => '29',
                ],
            ],
            [
                'sort_order' => 5,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'A paper has 10 questions. Kabir got 5 correct and attempted 7 in all. Correct answers give +2, wrong answers give −1, and not attempted give 0. Find his marks.',
                'answers' => [
                    'correct' => ['marks' => '2', 'count' => '5', 'total' => '10'],
                    'wrong' => ['marks' => '-1', 'count' => '2', 'total' => '-2'],
                    'grand_total' => '8',
                ],
            ],
            [
                'sort_order' => 6,
                'difficulty' => 'hard',
                'is_demo' => false,
                'question_text' => 'A paper has 15 questions. Sara attempted 12 and got 8 correct. Marks: +4 for correct, −1 for wrong, 0 for not attempted. What is her final score?',
                'answers' => [
                    'correct' => ['marks' => '4', 'count' => '8', 'total' => '32'],
                    'wrong' => ['marks' => '-1', 'count' => '4', 'total' => '-4'],
                    'grand_total' => '28',
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
                'is_demo' => $exercise['is_demo'],
                'question_text' => $exercise['question_text'],
                'answers' => json_encode($exercise['answers']),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('mmm_cards')->where('id', $cardId)->update([
            'method_blurb' => 'For marks-type word problems, first make a table: Correct / Wrong rows, then Marks × No. of questions = Total. Add the totals to get the final score. Sometimes wrong (or correct) is not written directly — work it out from attempted and total questions. Not attempted usually means 0 marks, so it may not need its own row.',
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
            ->whereIn('sort_order', [2, 3, 4, 5, 6])
            ->delete();
    }
};
