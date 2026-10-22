<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'balance-equation-basic-5')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'balance-equation-basic-5',
            'title' => 'Balance the equation — Basic 5',
            'heading' => 'Balance the equation — Basic 5',
            'kind' => 'balance_equation',
            'status' => 'published',
            'accent' => 'rose',
            'method_blurb' => 'When both sides are fractions, first put brackets around every numerator that has + or −. Then make one denominator on the left and one on the right. Open the brackets, finish the numerator, then cross.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 7,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $header = [
            [
                'title' => 'Left',
                'terms' => [
                    ['num' => '(x − 2)', 'den' => '3'],
                    ['op' => '−', 'num' => '(2x − 3)', 'den' => '2'],
                ],
            ],
            [
                'title' => 'Right',
                'terms' => [
                    ['num' => '2', 'den' => '3'],
                    ['op' => '−', 'num' => '1', 'den' => '5'],
                ],
            ],
        ];

        $exercises = [
            [
                'sort_order' => 1,
                'difficulty' => 'medium',
                'is_demo' => true,
                'question_text' => 'Solve (x − 2)/3 − (2x − 3)/2 = 2/3 − 1/5. Brackets first, then one denominator on each side.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Put brackets around a numerator that has + or −. Then one denominator on the left and one on the right. Open the brackets, finish the numerator, then cross.',
                    'play' => [
                        'given_left' => '(x − 2)/3 − (2x − 3)/2',
                        'given_right' => '2/3 − 1/5',
                        'header' => $header,
                        'scale_tip' => 'Brackets first. Then one denominator on each side. Open the brackets, finish the numerator, then cross.',
                        'steps' => [
                            [
                                'key' => 'brackets',
                                'type' => 'note_next',
                                'value' => 'ok',
                                'hint' => 'A numerator with + or − needs brackets before you make one denominator.',
                                'before' => [
                                    ['num' => 'x − 2', 'den' => '3'],
                                    ['op' => '−', 'num' => '2x − 3', 'den' => '2'],
                                    ['op' => '=', 'num' => '2', 'den' => '3'],
                                    ['op' => '−', 'num' => '1', 'den' => '5'],
                                ],
                                'after' => [
                                    ['num' => '(x − 2)', 'den' => '3'],
                                    ['op' => '−', 'num' => '(2x − 3)', 'den' => '2'],
                                    ['op' => '=', 'num' => '2', 'den' => '3'],
                                    ['op' => '−', 'num' => '1', 'den' => '5'],
                                ],
                                'done' => '(x − 2)/3 − (2x − 3)/2 = 2/3 − 1/5',
                            ],
                            [
                                'key' => 'denoms',
                                'type' => 'frac_board',
                                'hint' => 'Left: 3 and 2, so the denominator is 6. Multiply the first fraction by 2 and the second by 3. Right: 3 and 5, so the denominator is 15. Multiply 2/3 by 5 and 1/5 by 3.',
                                'miss' => 'Left ×2 and ×3, denominator 6. Right ×5 and ×3, denominator 15.',
                                'columns' => [
                                    [
                                        'title' => 'Left',
                                        'terms' => [
                                            ['num' => '(x − 2)', 'den' => '3', 'box' => 'ml1', 'label' => 'Multiply the first left fraction'],
                                            ['op' => '−', 'num' => '(2x − 3)', 'den' => '2', 'box' => 'ml2', 'label' => 'Multiply the second left fraction'],
                                        ],
                                        'den_box' => 'denL',
                                        'den_label' => 'Left denominator',
                                        'top' => '2(x − 2) − 3(2x − 3)',
                                    ],
                                    [
                                        'title' => 'Right',
                                        'terms' => [
                                            ['num' => '2', 'den' => '3', 'box' => 'mr1', 'label' => 'Multiply 2/3'],
                                            ['op' => '−', 'num' => '1', 'den' => '5', 'box' => 'mr2', 'label' => 'Multiply 1/5'],
                                        ],
                                        'den_box' => 'denR',
                                        'den_label' => 'Right denominator',
                                        'top' => '5 × 2 − 3 × 1',
                                    ],
                                ],
                                'done' => '[2(x − 2) − 3(2x − 3)] / 6 = (10 − 3) / 15',
                            ],
                            [
                                'key' => 'open',
                                'type' => 'stacked_line',
                                'hint' => 'Open the brackets. 2(x − 2) = 2x − 4. The minus in front of 3(2x − 3) flips the inside: −6x + 9. On the right, 10 − 3.',
                                'miss' => '2 × 2 = 4. 3 × 2 = 6. 3 × 3 = 9. 10 − 3 = 7.',
                                'from' => '[2(x − 2) − 3(2x − 3)] / 6 = (10 − 3) / 15',
                                'left' => [
                                    'den' => '6',
                                    'pieces' => [
                                        ['text' => '2x −'],
                                        ['box' => 'a4', 'label' => '2 × 2'],
                                        ['text' => '−'],
                                        ['box' => 'a6', 'label' => '3 × 2'],
                                        ['text' => 'x +'],
                                        ['box' => 'a9', 'label' => '3 × 3'],
                                    ],
                                ],
                                'right' => [
                                    'den' => '15',
                                    'pieces' => [
                                        ['box' => 'seven', 'label' => '10 − 3'],
                                    ],
                                ],
                                'done' => '(2x − 4 − 6x + 9) / 6 = 7/15',
                            ],
                            [
                                'key' => 'combine',
                                'type' => 'stacked_line',
                                'hint' => 'Finish the numerator. 2x − 6x, then −4 + 9. The right side stays 7/15.',
                                'miss' => '2x − 6x = −4x. −4 + 9 = 5.',
                                'from' => '(2x − 4 − 6x + 9) / 6 = 7/15',
                                'left' => [
                                    'den' => '6',
                                    'pieces' => [
                                        ['box' => 'nx', 'label' => '2x − 6x'],
                                        ['text' => 'x +'],
                                        ['box' => 'n5', 'label' => '−4 + 9'],
                                    ],
                                ],
                                'right' => [
                                    'den' => '15',
                                    'pieces' => [
                                        ['text' => '7'],
                                    ],
                                ],
                                'done' => '(−4x + 5) / 6 = 7/15',
                            ],
                            [
                                'key' => 'cross',
                                'type' => 'copy_line',
                                'hint' => 'Cross. The left denominator 6 meets 7. The right denominator 15 meets (−4x + 5). Then 7 × 6.',
                                'miss' => '15(−4x + 5) = 7 × 6, and 7 × 6 = 42.',
                                'from' => '(−4x + 5) / 6 = 7/15',
                                'parts' => [
                                    ['box' => 'crossL', 'label' => 'Right denominator crosses to the left'],
                                    ['text' => '(−4x + 5) = 7 ×'],
                                    ['box' => 'crossR', 'label' => 'Left denominator crosses to the right'],
                                    ['text' => '='],
                                    ['box' => 'prod', 'label' => '7 × 6'],
                                ],
                                'done' => '15(−4x + 5) = 42',
                            ],
                            [
                                'key' => 'shift',
                                'type' => 'copy_line',
                                'hint' => '15 × (−4x) = −60x and 15 × 5 = 75. So −60x + 75 = 42. Move 75: 42 − 75.',
                                'miss' => '42 − 75 = −33.',
                                'from' => '−60x + 75 = 42',
                                'parts' => [
                                    ['text' => '−60x ='],
                                    ['box' => 'shift', 'label' => '42 − 75'],
                                ],
                                'done' => '−60x = −33',
                            ],
                            [
                                'key' => 'x',
                                'type' => 'copy_line',
                                'hint' => '−33 ÷ −60. Both signs are minus, so the answer is positive. Simplify 33/60.',
                                'miss' => 'x = 11/20.',
                                'from' => '−60x = −33',
                                'parts' => [
                                    ['text' => 'x ='],
                                    ['box' => 'x', 'label' => 'Value of x'],
                                ],
                                'done' => 'x = 11/20',
                            ],
                        ],
                        'tally' => 'Brackets, then [2(x − 2) − 3(2x − 3)] / 6 = 7/15. Open to (−4x + 5)/6 = 7/15. Cross: 15(−4x + 5) = 42, so x = 11/20.',
                    ],
                    'check' => [
                        'ml1' => '2',
                        'ml2' => '3',
                        'denL' => '6',
                        'mr1' => '5',
                        'mr2' => '3',
                        'denR' => '15',
                        'a4' => '4',
                        'a6' => '6',
                        'a9' => '9',
                        'seven' => '7',
                        'nx' => '-4',
                        'n5' => '5',
                        'crossL' => '15',
                        'crossR' => '6',
                        'prod' => '42',
                        'shift' => '-33',
                        'x' => '11/20',
                    ],
                ],
            ],
            [
                'sort_order' => 2,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Three sums. Brackets first, one denominator on each side, then cross. Write x.',
                'answers' => [
                    'mode' => 'fill_page',
                    'variable_tip' => 'Same order every time. Brackets around a numerator that has + or −. One denominator on the left and one on the right. Open the brackets, finish the numerator, then cross.',
                    'play' => [
                        'page_blurb' => 'Solve in your copy. Brackets, one denominator on each side, open the brackets, then cross. Write x. If you cannot get it, ask your teacher to look at your working.',
                        'items' => [
                            [
                                'key' => 'q1',
                                'equation' => '(x + 1)/2 − (x − 1)/3 = 1/2 − 1/3',
                                'hint' => 'Left denominator 6: ×3 and ×2. The minus in front of (x − 1) flips the inside. Right side is 1/6. Then cross.',
                            ],
                            [
                                'key' => 'q2',
                                'equation' => '(2x − 1)/3 − (x + 2)/6 = 1/2 − 1/3',
                                'hint' => 'Left denominator 6: the first fraction ×2, the second ×1. Right side is 1/6. Finish the numerator, then cross.',
                            ],
                            [
                                'key' => 'q3',
                                'equation' => '(x − 3)/4 + (x + 1)/2 = 5/4 − 1/2',
                                'hint' => 'This one is a plus between the left fractions. Denominator 4: the second fraction ×2. Right side is 3/4. Then cross.',
                            ],
                        ],
                    ],
                    'check' => [
                        'q1' => '-4',
                        'q2' => '5/3',
                        'q3' => '4/3',
                    ],
                ],
            ],
        ];

        foreach ($exercises as $exercise) {
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

        $sourceIds = DB::table('mmm_cards')
            ->whereIn('slug', [
                'balance-the-equation',
                'balance-equation-basic-2',
                'balance-equation-basic-3',
                'balance-equation-basic-4',
            ])
            ->pluck('id');
        $chapterIds = DB::table('mmm_card_syllabus_chapter')
            ->whereIn('mmm_card_id', $sourceIds)
            ->pluck('syllabus_chapter_id')
            ->unique();
        foreach ($chapterIds as $chapterId) {
            DB::table('mmm_card_syllabus_chapter')->insert([
                'mmm_card_id' => $cardId,
                'syllabus_chapter_id' => $chapterId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-5')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
