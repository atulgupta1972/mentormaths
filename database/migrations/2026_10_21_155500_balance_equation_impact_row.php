<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** After adding the opposite, next row is x + 0 = [box] — student fills only that box. */
return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-the-equation')->value('id');
        if (! $cardId) {
            return;
        }

        $updates = [
            1 => [
                'variable_tip' => 'x has +5 stuck to it. Add −5 on both sides so +5 and −5 cancel. The scale stays level.',
                'play' => [
                    'given_left' => 'x + 5',
                    'given_right' => '10',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Type the opposite of +5 in the boxes. The same number appears on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'right', 'hint' => '5 + (−5) = 0, so the left becomes x + 0. Only fill the right box: 10 + (−5) = ?'],
                    ],
                    'tally' => 'x + 0 = 5, so x = 5. Check: 5 + 5 = 10.',
                ],
                'check' => ['add' => '-5', 'impact' => '5'],
            ],
            2 => [
                'variable_tip' => 'x has −5 stuck to it. Add +5 on both sides so they cancel.',
                'play' => [
                    'given_left' => 'x − 5',
                    'given_right' => '10',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Type the opposite of −5 (that is +5, or just 5) on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'right', 'hint' => '−5 + 5 = 0, so the left becomes x + 0. Only fill the right box: 10 + 5 = ?'],
                    ],
                    'tally' => 'x + 0 = 15, so x = 15. Check: 15 − 5 = 10.',
                ],
                'check' => ['add' => '5', 'impact' => '15'],
            ],
            3 => [
                'play' => [
                    'given_left' => 'x + 10',
                    'given_right' => '23',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +10 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'right', 'hint' => '10 + (−10) = 0, so the left is x + 0. Only fill the right box.'],
                    ],
                    'tally' => 'x + 0 = 13, so x = 13. Check: 13 + 10 = 23.',
                ],
                'check' => ['add' => '-10', 'impact' => '13'],
            ],
            4 => [
                'play' => [
                    'given_left' => 'x − 7',
                    'given_right' => '18',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of −7 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'right', 'hint' => '−7 + 7 = 0, so the left is x + 0. Only fill the right box: 18 + 7 = ?'],
                    ],
                    'tally' => 'x + 0 = 25, so x = 25. Check: 25 − 7 = 18.',
                ],
                'check' => ['add' => '7', 'impact' => '25'],
            ],
            5 => [
                'play' => [
                    'given_left' => 'x + 9',
                    'given_right' => '4',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +9 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'right', 'hint' => '9 + (−9) = 0, so the left is x + 0. Only fill the right box. Mind the sign.'],
                    ],
                    'tally' => 'x + 0 = −5, so x = −5. Check: −5 + 9 = 4.',
                ],
                'check' => ['add' => '-9', 'impact' => '-5'],
            ],
            6 => [
                'play' => [
                    'given_left' => 'x − 3',
                    'given_right' => '−8',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of −3 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'right', 'hint' => '−3 + 3 = 0, so the left is x + 0. Only fill the right box: −8 + 3 = ?'],
                    ],
                    'tally' => 'x + 0 = −5, so x = −5. Check: −5 − 3 = −8.',
                ],
                'check' => ['add' => '3', 'impact' => '-5'],
            ],
            7 => [
                'variable_tip' => 'Same rule: add the opposite of +30 on both sides. x will be left alone on the right.',
                'play' => [
                    'given_left' => '20',
                    'given_right' => 'x + 30',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +30 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'left', 'hint' => '30 + (−30) = 0, so the right becomes x + 0. Only fill the left box: 20 + (−30) = ?'],
                    ],
                    'tally' => '−10 = x + 0, so x = −10. Check: −10 + 30 = 20.',
                ],
                'check' => ['add' => '-30', 'impact' => '-10'],
            ],
            8 => [
                'play' => [
                    'given_left' => '15',
                    'given_right' => 'x − 5',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of −5 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => 'x + 0', 'box_side' => 'left', 'hint' => '−5 + 5 = 0, so the right is x + 0. Only fill the left box: 15 + 5 = ?'],
                    ],
                    'tally' => '20 = x + 0, so x = 20. Check: 20 − 5 = 15.',
                ],
                'check' => ['add' => '5', 'impact' => '20'],
            ],
            9 => [
                'play' => [
                    'given_left' => '8 + x',
                    'given_right' => '2',
                    'steps' => [
                        ['key' => 'add', 'type' => 'balance', 'hint' => 'Add the opposite of +8 on both sides.'],
                        ['key' => 'impact', 'type' => 'impact', 'x_expr' => '0 + x', 'box_side' => 'right', 'hint' => '8 + (−8) = 0, so the left becomes 0 + x. Only fill the right box: 2 + (−8) = ?'],
                    ],
                    'tally' => '0 + x = −6, so x = −6. Check: 8 + (−6) = 2.',
                ],
                'check' => ['add' => '-8', 'impact' => '-6'],
            ],
        ];

        foreach ($updates as $sortOrder => $extra) {
            $row = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', $sortOrder)
                ->first();
            if (! $row) {
                continue;
            }
            $answers = json_decode($row->answers, true) ?: [];
            $answers = array_merge($answers, $extra, ['mode' => 'balance']);
            unset($answers['check']['x']);
            $answers['check'] = $extra['check'];
            DB::table('mmm_card_exercises')->where('id', $row->id)->update([
                'answers' => json_encode($answers),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
