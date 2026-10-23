<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-5')->value('id');
        if (! $cardId) {
            return;
        }

        $row = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 2)
            ->first();
        if (! $row) {
            return;
        }

        $answers = json_decode($row->answers, true);
        $items = (is_array($answers) && is_array($answers['play']['items'] ?? null)) ? $answers['play']['items'] : [];
        if (count($items) >= 10 && ! empty($items[0]['terms'])) {
            return;
        }

        $next = [
                    'mode' => 'fill_page',
                    'variable_tip' => 'Same order every time. Brackets around a numerator that has + or −. One denominator on each side. A whole term is a fraction over 1. Open the brackets, finish the numerator, then cross.',
                    'play' => [
                        'page_blurb' => 'Solve in your copy. Each fraction is written with the numerator above the line. Brackets, one denominator on each side, then cross. Write the letter.',
                        'items' => [
                            [
                                'key' => 'q1',
                                'variable' => 'm',
                                'hint' => '−m is a whole term. Put brackets on (m − 2). One denominator for 2 and 3, then move the m-terms.',
                                'terms' => [
                                    ['num' => '−m'],
                                    ['op' => '−', 'num' => 'm − 2', 'den' => '2'],
                                    ['eq' => true],
                                    ['num' => '−m + 4', 'den' => '3'],
                                    ['op' => '+', 'num' => '1', 'den' => '2'],
                                ],
                            ],
                            [
                                'key' => 'q2',
                                'variable' => 'm',
                                'hint' => '−m/3 is a fraction and (m − 2) is a whole term. One denominator, then move the plain m on the right.',
                                'terms' => [
                                    ['num' => '−m', 'den' => '3'],
                                    ['op' => '−', 'num' => '(m − 2)'],
                                    ['eq' => true],
                                    ['num' => '2', 'den' => '7'],
                                    ['op' => '−', 'num' => 'm'],
                                ],
                            ],
                            [
                                'key' => 'q3',
                                'variable' => 'm',
                                'hint' => 'The 3 and the 4 in front belong to the numerators. One denominator on the left (4 and 3) and one on the right. Open the brackets, then cross.',
                                'terms' => [
                                    ['num' => '3(m − 2)', 'den' => '4'],
                                    ['op' => '−', 'num' => '4(m − 1)', 'den' => '3'],
                                    ['eq' => true],
                                    ['num' => '3', 'den' => '4'],
                                    ['op' => '−', 'num' => '4', 'den' => '3'],
                                ],
                            ],
                            [
                                'key' => 'q4',
                                'variable' => 'x',
                                'hint' => 'Same as the lesson. Left ×2 and ×3, denominator 6. Right ×5 and ×3, denominator 15. Open the brackets, then cross.',
                                'terms' => [
                                    ['num' => '(x − 2)', 'den' => '3'],
                                    ['op' => '−', 'num' => '(2x − 3)', 'den' => '2'],
                                    ['eq' => true],
                                    ['num' => '2', 'den' => '3'],
                                    ['op' => '−', 'num' => '1', 'den' => '5'],
                                ],
                            ],
                            [
                                'key' => 'q5',
                                'variable' => 'x',
                                'hint' => 'Left denominator 6. The minus in front of (x − 1) flips the inside. Right side becomes 1/6. Then cross.',
                                'terms' => [
                                    ['num' => '(x + 1)', 'den' => '2'],
                                    ['op' => '−', 'num' => '(x − 1)', 'den' => '3'],
                                    ['eq' => true],
                                    ['num' => '1', 'den' => '2'],
                                    ['op' => '−', 'num' => '1', 'den' => '3'],
                                ],
                            ],
                            [
                                'key' => 'q6',
                                'variable' => 'x',
                                'hint' => 'Left denominator 6. The first fraction ×2, the second ×1. Right side is 1/6. Finish the numerator, then cross.',
                                'terms' => [
                                    ['num' => '(2x − 1)', 'den' => '3'],
                                    ['op' => '−', 'num' => '(x + 2)', 'den' => '6'],
                                    ['eq' => true],
                                    ['num' => '1', 'den' => '2'],
                                    ['op' => '−', 'num' => '1', 'den' => '3'],
                                ],
                            ],
                            [
                                'key' => 'q7',
                                'variable' => 'x',
                                'hint' => 'Plus between the left fractions. Denominator 4: the second fraction ×2. Right side is 3/4. Then cross.',
                                'terms' => [
                                    ['num' => '(x − 3)', 'den' => '4'],
                                    ['op' => '+', 'num' => '(x + 1)', 'den' => '2'],
                                    ['eq' => true],
                                    ['num' => '5', 'den' => '4'],
                                    ['op' => '−', 'num' => '1', 'den' => '2'],
                                ],
                            ],
                            [
                                'key' => 'q8',
                                'variable' => 'x',
                                'hint' => 'A whole −x sits beside a fraction. One denominator for 2 and 3, then gather the x-terms. Numbers move last.',
                                'terms' => [
                                    ['num' => '−x'],
                                    ['op' => '−', 'num' => 'x + 4', 'den' => '2'],
                                    ['eq' => true],
                                    ['num' => '−x + 1', 'den' => '3'],
                                    ['op' => '+', 'num' => '1', 'den' => '2'],
                                ],
                            ],
                            [
                                'key' => 'q9',
                                'variable' => 'x',
                                'hint' => '−x/5 is a fraction and (x − 1) is a whole term. The plain x on the right moves across. Then one denominator.',
                                'terms' => [
                                    ['num' => '−x', 'den' => '5'],
                                    ['op' => '−', 'num' => '(x − 1)'],
                                    ['eq' => true],
                                    ['num' => '3', 'den' => '5'],
                                    ['op' => '−', 'num' => 'x'],
                                ],
                            ],
                            [
                                'key' => 'q10',
                                'variable' => 'x',
                                'hint' => 'The 2 and the 3 in front belong to the numerators. One denominator on each side, open the brackets, then cross.',
                                'terms' => [
                                    ['num' => '2(x − 3)', 'den' => '5'],
                                    ['op' => '−', 'num' => '3(x − 1)', 'den' => '2'],
                                    ['eq' => true],
                                    ['num' => '4', 'den' => '5'],
                                    ['op' => '−', 'num' => '3', 'den' => '2'],
                                ],
                            ],
                        ],
                    ],
                    'check' => [
                        'q1' => '-5/7',
                        'q2' => '36/7',
                        'q3' => '5/7',
                        'q4' => '11/20',
                        'q5' => '-4',
                        'q6' => '5/3',
                        'q7' => '4/3',
                        'q8' => '-17/7',
                        'q9' => '2',
                        'q10' => '10/11',
                    ],
                ];

        DB::table('mmm_card_exercises')->where('id', $row->id)->update([
            'question_text' => 'Ten sums. Some have a whole term beside a fraction, and some have a number in front of the bracket. Numerator above the line, denominator below. Write the letter.',
            'answers' => json_encode($next),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }
};
