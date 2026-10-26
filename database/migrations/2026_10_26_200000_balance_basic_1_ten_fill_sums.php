<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-the-equation')->value('id');
        if (! $cardId) {
            return;
        }

        $existing = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 13)
            ->first();
        if ($existing) {
            return;
        }

        $items = [
            ['key' => 'q1', 'left' => 'x + 7', 'right' => '19', 'answer' => '12', 'hint' => 'Add −7 on both sides. 19 − 7 = 12, so x = 12.'],
            ['key' => 'q2', 'left' => 'x − 4', 'right' => '11', 'answer' => '15', 'hint' => 'Add +4 on both sides. 11 + 4 = 15, so x = 15.'],
            ['key' => 'q3', 'left' => 'x + 12', 'right' => '5', 'answer' => '-7', 'hint' => 'Add −12 on both sides. 5 − 12 = −7, so x = −7.'],
            ['key' => 'q4', 'left' => 'x − 6', 'right' => '−2', 'answer' => '4', 'hint' => 'Add +6 on both sides. −2 + 6 = 4, so x = 4.'],
            ['key' => 'q5', 'left' => '16', 'right' => 'x + 9', 'answer' => '7', 'hint' => 'x is on the right. Add −9 on both sides. 16 − 9 = 7, so x = 7.'],
            ['key' => 'q6', 'left' => '9', 'right' => 'x − 8', 'answer' => '17', 'hint' => 'x is on the right. Add +8 on both sides. 9 + 8 = 17, so x = 17.'],
            ['key' => 'q7', 'left' => '3 + x', 'right' => '14', 'answer' => '11', 'hint' => 'Add −3 on both sides. 14 − 3 = 11, so x = 11.'],
            ['key' => 'q8', 'left' => 'x + 4', 'right' => '30 − 6', 'answer' => '20', 'hint' => 'Tidy the right first: 30 − 6 = 24. Then add −4. 24 − 4 = 20, so x = 20.'],
            ['key' => 'q9', 'left' => 'x − 8', 'right' => '12 + 15', 'answer' => '35', 'hint' => 'Tidy the right first: 12 + 15 = 27. Then add +8. 27 + 8 = 35, so x = 35.'],
            ['key' => 'q10', 'left' => '40 − 13', 'right' => 'x + 9', 'answer' => '18', 'hint' => 'Tidy the left first: 40 − 13 = 27. Then add −9. 27 − 9 = 18, so x = 18.'],
        ];

        $check = [];
        $playItems = [];
        foreach ($items as $item) {
            $check[$item['key']] = $item['answer'];
            $playItems[] = [
                'key' => $item['key'],
                'equation' => $item['left'].' = '.$item['right'],
                'left' => $item['left'],
                'right' => $item['right'],
                'label' => 'x =',
                'hint' => $item['hint'],
            ];
        }

        $now = now();
        DB::table('mmm_card_exercises')->insert([
            'mmm_card_id' => $cardId,
            'sort_order' => 13,
            'difficulty' => 'practice',
            'is_demo' => false,
            'question_text' => 'Ten sums. Solve each in your copy, then fill x. If you cannot solve one, open the help — it shows the step. You still type x yourself.',
            'answers' => json_encode([
                'mode' => 'fill_page',
                'variable_tip' => 'Same method as this part: add the opposite of the number stuck to x, on both sides. If a side has two numbers, add them first.',
                'wrong_message' => 'Not yet. Add the opposite on both sides. If you cannot see it, open “I cannot solve this”.',
                'check' => $check,
                'play' => [
                    'page_blurb' => 'Ten sums on freeing x. + and − only.',
                    'items' => $playItems,
                ],
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-the-equation')->value('id');
        if (! $cardId) {
            return;
        }

        DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->where('sort_order', 13)->delete();
    }
};
