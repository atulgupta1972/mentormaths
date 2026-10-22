<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('mmm_cards')->where('slug', 'balance-equation-basic-4')->exists()) {
            return;
        }

        $now = now();
        $cardId = DB::table('mmm_cards')->insertGetId([
            'slug' => 'balance-equation-basic-4',
            'title' => 'Balance the equation — Basic 4',
            'heading' => 'Balance the equation — Basic 4',
            'kind' => 'balance_equation',
            'status' => 'published',
            'accent' => 'rose',
            'method_blurb' => 'When x and numbers are on both sides, first move every x-term to one side — left or right, both are fine. If an x-term is a fraction, solve that fraction the usual way, then put x back. If a side has brackets or several numbers, tidy that side first. Move the plain numbers last.',
            'table_schema' => json_encode(['columns' => [], 'rows' => []]),
            'sort_order' => 6,
            'created_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $pick = function (string $key, string $prompt, string $hint, string $leftLabel, string $rightLabel, array $lhs, array $rhs, ?string $now = null): array {
            return [
                'key' => $key,
                'type' => 'pick_side',
                'prompt' => $prompt,
                'hint' => $hint,
                'now' => $now,
                'miss' => 'Left or right — both are correct. Choose one.',
                'choices' => [
                    ['value' => 'lhs', 'label' => $leftLabel],
                    ['value' => 'rhs', 'label' => $rightLabel],
                ],
                'branches' => [
                    'lhs' => $lhs,
                    'rhs' => $rhs,
                ],
            ];
        };

        $xTerm = fn (string $key, string $expr, string $hint, string $now, string $miss) => [
            'key' => $key,
            'type' => 'x_term',
            'expr' => $expr,
            'hint' => $hint,
            'now' => $now,
            'miss' => $miss,
        ];

        $blank = fn (string $key, string $label, string $before, ?string $after, string $hint, string $now, string $miss) => [
            'key' => $key,
            'type' => 'blank',
            'label' => $label,
            'before' => $before,
            'after' => $after,
            'hint' => $hint,
            'now' => $now,
            'miss' => $miss,
        ];

        $flip = [
            'key' => 'flip',
            'type' => 'keep_flip',
            'keep' => '90 / 7',
            'flip_from' => '7 / 3',
            'hint' => '90/7 stays. The 7/3 in front of x flips to 3/7.',
            'now' => 'Now it is 90/7 = 7x/3.',
            'miss' => 'Flip 7/3. The top and bottom swap: 3 over 7.',
        ];

        $solveFraction = [
            'key' => 'x',
            'type' => 'solve',
            'hint' => 'Multiply the two tops. Multiply the two bottoms. Write the fraction.',
            'rewrite_note' => 'x = 90/7 × 3/7.',
            'miss' => '90 × 3 over 7 × 7. Write that fraction.',
        ];

        $tidyLeft = [
            [
                'key' => 'br',
                'type' => 'open_bracket',
                'expression' => '−4 − 9',
                'hint' => 'Work out the inside of the bracket only.',
                'now' => 'Left side: 2x − [−4 − 9] + 12.',
                'miss' => 'Inside the bracket: −4 − 9. The answer is negative.',
            ],
            $blank(
                'sign',
                'The minus in front flips the bracket',
                '−[−13] =',
                null,
                'A minus in front of a negative becomes plus.',
                'The bracket was −13. There is still a minus in front of it.',
                '−[−13] is +13. A minus and a minus make plus.',
            ),
            $blank(
                'addn',
                'Add the loose numbers on the left',
                '13 + 12 =',
                null,
                'The left side is now 2x plus these two numbers.',
                '−[−13] became +13. The +12 is still there.',
                '13 + 12. That number stays with 2x.',
            ),
        ];

        $tidyRight = [
            $xTerm(
                'rx',
                '3x − 5x',
                'Join the x-terms on the right. Write the x-term, such as −2x.',
                'Right side starts with 3x − 5x. The number in the bracket waits.',
                '3x − 5x is one x-term. Write it with x, and mind the sign.',
            ),
            $blank(
                'rnum',
                'The number on the right',
                '−20 + 40 =',
                null,
                'Finish the bracket (−20 + 40). The x-term stays as it is.',
                'The right side is an x-term plus this number.',
                '−20 + 40. The answer is positive.',
            ),
        ];

        $moveLeft = [
            $xTerm(
                'join_l',
                '2x + 2x',
                '−2x moved to the left, so it is +2x. Join the x-terms.',
                'Now: 2x + 2x + 25 = 20. Numbers stay until last.',
                '2x + 2x is one x-term. Write 4x.',
            ),
            [
                'key' => 'take_l',
                'type' => 'take_number',
                'keep' => '4x',
                'frozen' => '20',
                'op' => '−',
                'hint' => 'Numbers last. +25 moves across as −25.',
                'now' => '4x + 25 = 20.',
                'miss' => 'Take 25 across. The box is 25.',
            ],
            [
                'key' => 'term_l',
                'type' => 'term_value',
                'keep' => '4x',
                'hint' => '20 − 25. Mind the sign.',
                'miss' => '20 − 25 is negative.',
            ],
            [
                'key' => 'x_l',
                'type' => 'solve',
                'hint' => '4x = −5. Divide by 4. A fraction is fine.',
                'rewrite_note' => 'x = −5/4.',
                'miss' => 'Divide −5 by 4. Write −5/4 or −1.25.',
            ],
        ];

        $moveRight = [
            $xTerm(
                'join_r',
                '−2x − 2x',
                '2x moved to the right, so it is −2x. Join the x-terms.',
                'Now: 25 = −2x − 2x + 20. Numbers stay until last.',
                '−2x − 2x is one x-term. Write −4x.',
            ),
            [
                'key' => 'take_r',
                'type' => 'take_number',
                'keep' => '−4x',
                'frozen' => '25',
                'op' => '−',
                'hint' => 'Numbers last. +20 moves across as −20.',
                'now' => '25 = −4x + 20.',
                'miss' => 'Take 20 across. The box is 20.',
            ],
            [
                'key' => 'term_r',
                'type' => 'term_value',
                'keep' => '−4x',
                'hint' => '25 − 20.',
                'miss' => '25 − 20.',
            ],
            [
                'key' => 'x_r',
                'type' => 'solve',
                'hint' => '−4x = 5, so x = 5 ÷ −4.',
                'rewrite_note' => 'x = −5/4.',
                'miss' => '5 divided by −4 is −5/4.',
            ],
        ];

        $movePick = $pick(
            'move',
            'Where should the x-terms go?',
            'Like terms next. Move every x to the left or to the right. Leave 25 and 20 until last.',
            'Move x to the left',
            'Move x to the right',
            $moveLeft,
            $moveRight,
            'Now it is 2x + 25 = −2x + 20.',
        );

        $exercises = [
            [
                'sort_order' => 1,
                'difficulty' => 'demo',
                'is_demo' => true,
                'question_text' => 'Solve 2x + 40 = 3x − 50. Move the x-terms to one side.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'x and a number are on both sides. First gather every x on one side — left or right, both are correct. When 3x moves left it becomes −3x. When 2x moves right it becomes −2x. Join those x-terms. Move 40 and −50 last.',
                    'play' => [
                        'given_left' => '2x + 40',
                        'given_right' => '3x − 50',
                        'scale_tip' => 'Pick one side for x. The plain numbers move after the x-terms are together.',
                        'steps' => [
                            $pick(
                                'side',
                                'Where should the x-terms go?',
                                'Move every x to the left, or to the right. Then join them. Numbers wait.',
                                'Move x to the left',
                                'Move x to the right',
                                [
                                    $xTerm(
                                        'join_l',
                                        '2x − 3x',
                                        '3x has crossed, so it is −3x. Write the single x-term.',
                                        '2x − 3x + 40 = −50. Leave 40 for later.',
                                        '2x − 3x is −x. You can write −x or −1x.',
                                    ),
                                    [
                                        'key' => 'take_l',
                                        'type' => 'take_number',
                                        'keep' => '−x',
                                        'frozen' => '−50',
                                        'op' => '−',
                                        'hint' => 'Numbers last. +40 moves across as −40.',
                                        'now' => '−x + 40 = −50.',
                                        'miss' => 'The number that moves is 40.',
                                    ],
                                    [
                                        'key' => 'term_l',
                                        'type' => 'term_value',
                                        'keep' => '−x',
                                        'hint' => '−50 − 40. Keep the minus.',
                                        'miss' => '−50 − 40 = −90.',
                                    ],
                                    [
                                        'key' => 'x_l',
                                        'type' => 'solve',
                                        'hint' => '−x = −90. Both sides have a minus, so x is the opposite of −90.',
                                        'rewrite_note' => '−x = −90 means x = 90.',
                                        'miss' => 'The opposite of −90 is 90.',
                                    ],
                                ],
                                [
                                    $xTerm(
                                        'join_r',
                                        '3x − 2x',
                                        '2x has crossed, so it is −2x. Write the single x-term.',
                                        '40 = 3x − 2x − 50. Leave −50 for later.',
                                        '3x − 2x is x. You can write x or 1x.',
                                    ),
                                    [
                                        'key' => 'take_r',
                                        'type' => 'take_number',
                                        'keep' => 'x',
                                        'frozen' => '40',
                                        'op' => '+',
                                        'hint' => 'Numbers last. −50 moves across as +50.',
                                        'now' => '40 = x − 50.',
                                        'miss' => 'The number that moves is 50.',
                                    ],
                                    [
                                        'key' => 'x_r',
                                        'type' => 'solve',
                                        'hint' => '40 + 50. That is x.',
                                        'rewrite_note' => 'x = 40 + 50.',
                                        'miss' => '40 + 50 = 90.',
                                    ],
                                ],
                            ),
                        ],
                        'tally' => 'Left path: 2x − 3x = −x, then −x = −90, so x = 90. Right path: 3x − 2x = x, then x = 40 + 50 = 90. Check: 2×90 + 40 = 220 and 3×90 − 50 = 220.',
                    ],
                    'check' => [
                        'join_l' => ['-x', '-1x', '-1'],
                        'take_l' => '40',
                        'term_l' => '-90',
                        'x_l' => '90',
                        'join_r' => ['x', '1x', '1', '+x'],
                        'take_r' => '50',
                        'x_r' => '90',
                    ],
                ],
            ],
            [
                'sort_order' => 2,
                'difficulty' => 'easy',
                'is_demo' => false,
                'question_text' => 'Solve 2x/3 + 40/7 = 3x − 50/7. Solve the fraction, then put x.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'A fraction with x is still an ordinary fraction. Ignore x, use a common denominator, then put x on the answer. 3x means 3x/1, so write 3 over the other denominator before you subtract. Move the plain fractions (40/7 and 50/7) last.',
                    'play' => [
                        'given_left' => '2x⁄3 + 40⁄7',
                        'given_right' => '3x − 50⁄7',
                        'scale_tip' => 'Move the x-terms first. Solve their fraction the usual way, then put x. Number fractions move last.',
                        'steps' => [
                            $pick(
                                'side',
                                'Where should the x-terms go?',
                                'Move every x to one side. A fraction such as 2x/3 is solved like 2/3, then you put x back.',
                                'Move x to the left',
                                'Move x to the right',
                                [
                                    [
                                        'key' => 'frac_l',
                                        'type' => 'common_den',
                                        'keep_num' => '2x',
                                        'keep_den' => '3',
                                        'from_num' => '3x',
                                        'from_den' => '1',
                                        'right_a' => '−50 / 7',
                                        'right_b' => '40 / 7',
                                        'right_op' => '−',
                                        'mul_key' => 'mul_l',
                                        'top_key' => 'rew_l',
                                        'sign_left' => '3x moved to the left, so the sign is minus.',
                                        'sign_right' => '+40/7 moved to the right, so the sign is minus.',
                                        'hint' => 'The equation is now 2x/3 − 3x = −50/7 − 40/7. Make the left denominators the same. Write only the number in each box.',
                                        'miss' => '3x is 3x/1. Multiply top and bottom by 3. 3 × 3 = 9, so the new top is 9x.',
                                    ],
                                    $blank(
                                        'nums_l',
                                        'Move the number fractions last',
                                        '−50/7 − 40/7 =',
                                        '/ 7',
                                        'The denominator stays 7. Write the top only, with the sign.',
                                        'Put x later. Left is 2x/3 − 9x/3, and 2x − 9x = −7x. Finish −50/7 − 40/7.',
                                        'The tops are −50 and −40. −50 − 40 = −90. Write −90.',
                                    ),
                                    [
                                        'key' => 'flip',
                                        'type' => 'keep_flip',
                                        'keep' => '90 / 7',
                                        'flip_from' => '7 / 3',
                                        'hint' => 'Both sides are negative, so the minuses cancel: 7x/3 = 90/7. Keep 90/7. Flip 7/3 to 3/7.',
                                        'now' => '−7x/3 = −90/7 is the same as 7x/3 = 90/7.',
                                        'miss' => 'Flip 7/3. The top and bottom swap: 3 over 7.',
                                    ],
                                    $solveFraction,
                                ],
                                [
                                    [
                                        'key' => 'frac_r',
                                        'type' => 'frac_x',
                                        'lead' => '3',
                                        'den' => '3',
                                        'other' => '2',
                                        'op' => '−',
                                        'order' => 'lead_first',
                                        'top_key' => 'rew_r',
                                        'result_key' => 'joined_r',
                                        'hint' => 'Right x-terms are 3 − 2/3. Write 3 as a fraction over 3, subtract, then we put x.',
                                        'now' => '2x/3 has moved right as −2x/3. Numbers 40/7 and −50/7 stay for later.',
                                        'miss' => '3 = 9/3. Then 9/3 − 2/3 = 7/3. The top of the answer is 7.',
                                    ],
                                    $blank(
                                        'nums_r',
                                        'Move the number fractions last',
                                        '40/7 + 50/7 =',
                                        '/ 7',
                                        'The denominator stays 7. Write the top only.',
                                        'Put x: 7x/3. So 40/7 + 50/7 = 7x/3.',
                                        '40 + 50 = 90. Write 90.',
                                    ),
                                    $flip,
                                    $solveFraction,
                                ],
                            ),
                        ],
                        'tally' => 'Either side: the x-terms become 7x/3 and the numbers become 90/7. Keep 90/7, flip 7/3 to 3/7, so x = 270/49. Check: 2×(270/49)/3 + 40/7 = 3×(270/49) − 50/7.',
                    ],
                    'check' => [
                        'rew_l' => '9',
                        'mul_l' => '3',
                        'nums_l' => '-90',
                        'rew_r' => '9',
                        'joined_r' => '7',
                        'nums_r' => '90',
                        'flip_n' => '3',
                        'flip_d' => '7',
                        'x' => '270/49',
                    ],
                ],
            ],
            [
                'sort_order' => 3,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Solve 2x − [−4 − 9] + 12 = 3x − 5x + (−20 + 40). Each line simplifies both sides.',
                'answers' => [
                    'mode' => 'balance',
                    'variable_tip' => 'Write the next line of the copy with (=). On each line, simplify the left and the right together. Brackets first, on both sides. Then move the x-terms. A number changes sign when it crosses.',
                    'play' => [
                        'given_left' => '2x − [−4 − 9] + 12',
                        'given_right' => '3x − 5x + (−20 + 40)',
                        'scale_tip' => 'Each step is the next line. Do the left side and the right side on that same line.',
                        'steps' => [
                            [
                                'key' => 'open',
                                'type' => 'copy_line',
                                'hint' => 'Solve both sides. Left: inside [−4 − 9]. Right: 3x − 5x, and −20 + 40.',
                                'miss' => 'Left bracket is −13. Right x-terms are −2x. The number on the right is 20.',
                                'parts' => [
                                    ['text' => '2x − ('],
                                    ['box' => 'br', 'label' => 'Inside the left bracket'],
                                    ['text' => ') + 12'],
                                    ['eq' => true],
                                    ['box' => 'rx', 'label' => '3x − 5x'],
                                    ['text' => '+ ('],
                                    ['box' => 'rnum', 'label' => '−20 + 40'],
                                    ['text' => ')'],
                                ],
                            ],
                            [
                                'key' => 'sign',
                                'type' => 'copy_line',
                                'from' => '2x − (−13) + 12 = −2x + (20)',
                                'hint' => '−(−13) changes sign. The right side stays −2x + 20.',
                                'miss' => 'A minus in front of −13 becomes +13.',
                                'parts' => [
                                    ['text' => '2x +'],
                                    ['box' => 'sign', 'label' => '−(−13)'],
                                    ['text' => '+ 12'],
                                    ['eq' => true],
                                    ['text' => '−2x + 20'],
                                ],
                            ],
                            [
                                'key' => 'addn',
                                'type' => 'copy_line',
                                'from' => '2x + 13 + 12 = −2x + 20',
                                'hint' => 'Add the numbers on the left. The right side stays −2x + 20.',
                                'miss' => '13 + 12 = 25.',
                                'parts' => [
                                    ['text' => '2x +'],
                                    ['box' => 'addn', 'label' => '13 + 12'],
                                    ['eq' => true],
                                    ['text' => '−2x + 20'],
                                ],
                            ],
                            [
                                'key' => 'move',
                                'type' => 'copy_line',
                                'from' => '2x + 25 = −2x + 20',
                                'hint' => '−2x crosses to the left and becomes +2x. +25 crosses to the right and becomes −25.',
                                'miss' => 'Write 2x on the left and 25 on the right.',
                                'parts' => [
                                    ['text' => '2x +'],
                                    ['box' => 'moved_x', 'label' => 'x-term that crossed'],
                                    ['eq' => true],
                                    ['text' => '20 −'],
                                    ['box' => 'moved_n', 'label' => 'number that crossed'],
                                ],
                            ],
                            [
                                'key' => 'simp',
                                'type' => 'copy_line',
                                'from' => '2x + 2x = 20 − 25',
                                'hint' => 'Simplify both sides. 2x + 2x on the left. 20 − 25 on the right. Mind the sign.',
                                'miss' => 'Left is 4x. Right is −5.',
                                'parts' => [
                                    ['box' => 'coef', 'label' => '2x + 2x'],
                                    ['eq' => true],
                                    ['box' => 'rhs_n', 'label' => '20 − 25'],
                                ],
                            ],
                            [
                                'key' => 'x',
                                'type' => 'copy_line',
                                'from' => '4x = −5',
                                'hint' => 'Divide both sides by 4. A fraction is fine.',
                                'miss' => '−5 ÷ 4 = −5/4.',
                                'parts' => [
                                    ['text' => 'x'],
                                    ['eq' => true],
                                    ['box' => 'x', 'label' => 'Value of x'],
                                ],
                            ],
                        ],
                        'tally' => '2x − (−13) + 12 = −2x + 20, then 2x + 25 = −2x + 20, then 2x + 2x = 20 − 25, so 4x = −5 and x = −5/4.',
                    ],
                    'check' => [
                        'br' => '-13',
                        'rx' => '-2x',
                        'rnum' => '20',
                        'sign' => ['13', '+13'],
                        'addn' => '25',
                        'moved_x' => ['2x', '+2x'],
                        'moved_n' => '25',
                        'coef' => '4x',
                        'rhs_n' => '-5',
                        'x' => '-5/4',
                    ],
                ],
            ],
            [
                'sort_order' => 4,
                'difficulty' => 'medium',
                'is_demo' => false,
                'question_text' => 'Five sums on this page. Tidy each side, move the x-terms, then the numbers. Write x.',
                'answers' => [
                    'mode' => 'fill_page',
                    'variable_tip' => 'Same order every time. If a side has brackets, finish that side first. Then move x-terms to one side. If an x-term is a fraction, solve the fraction and put x. Move plain numbers last.',
                    'play' => [
                        'page_blurb' => 'Solve in your copy. Tidy each side, gather the x-terms, then move the numbers. Write x. If you cannot get it, ask your teacher to look at your working.',
                        'items' => [
                            [
                                'key' => 'q1',
                                'equation' => '4x + 6 = 7x − 15',
                                'hint' => 'Move the x-terms to one side (7x − 4x). Move 6 and −15 last, then divide.',
                            ],
                            [
                                'key' => 'q2',
                                'equation' => 'x/2 + 5 = 3x/2 − 1',
                                'hint' => 'Move the x-terms together. x/2 and 3x/2 are fractions with x — subtract them like ordinary fractions, then put x. Move 5 and −1 last.',
                            ],
                            [
                                'key' => 'q3',
                                'equation' => '3x − [−1 − 5] + 2 = 6x − 2x + (−10 + 4)',
                                'hint' => 'Tidy the left side: open [−1 − 5], the minus in front flips it, then +2. Tidy the right side: 6x − 2x and −10 + 4. Then move x-terms. Numbers last.',
                            ],
                            [
                                'key' => 'q4',
                                'equation' => '2x/5 + 1/2 = x − 3/2',
                                'hint' => '2x/5 is a fraction with x. Move the x-terms: x − 2x/5. Solve that fraction and put x. Then add 1/2 and 3/2.',
                            ],
                            [
                                'key' => 'q5',
                                'equation' => 'x + 9 = 4x − 6',
                                'hint' => 'Move the x-terms to one side. Move 9 and −6 last, then divide.',
                            ],
                        ],
                    ],
                    'check' => [
                        'q1' => '7',
                        'q2' => '6',
                        'q3' => '14',
                        'q4' => '10/3',
                        'q5' => '5',
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
            ->whereIn('slug', ['balance-the-equation', 'balance-equation-basic-2', 'balance-equation-basic-3'])
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
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-4')->value('id');
        if (! $cardId) {
            return;
        }
        DB::table('mmm_cards')->where('id', $cardId)->delete();
    }
};
