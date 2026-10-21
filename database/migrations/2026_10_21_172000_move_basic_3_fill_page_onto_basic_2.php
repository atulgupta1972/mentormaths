<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-2')->value('id');
        if ($cardId) {
            DB::table('mmm_cards')->where('id', $cardId)->update([
                'method_blurb' => '2x is one term — keep it together. First tidy the extra numbers, then divide both sides by the same number so x stands alone. Open brackets and work the right-hand side before you take a number across. Last page: five copy sums with BODMAS.',
                'updated_at' => $now,
            ]);

            $already = DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', 4)
                ->exists();
            if (! $already) {
                DB::table('mmm_card_exercises')->insert([
                    'mmm_card_id' => $cardId,
                    'sort_order' => 4,
                    'difficulty' => 'medium',
                    'is_demo' => false,
                    'question_text' => 'Five sums on this page. Solve each in your copy, then write x. It checks as soon as you enter the answer.',
                    'answers' => json_encode([
                        'mode' => 'fill_page',
                        'variable_tip' => 'Do not skip BODMAS. Brackets first, then divide or multiply, then add and subtract. The x-term stays together until the numbers are tidy.',
                        'play' => [
                            'page_blurb' => 'Solve in your copy. BODMAS on the numbers first: brackets, then × and ÷, then + and −. Keep the x-term as one piece, then write x. If you cannot get it, ask your teacher to look at your working.',
                            'items' => [
                                [
                                    'key' => 'q1',
                                    'equation' => '30 − 5x = 25/5 − [−2 − 7] + [−9]',
                                    'hint' => 'BODMAS: 25/5 = 5. Next [−2 − 7] = −9, so −[−9] is +9, then +[−9] is −9. Work the right-hand side to one number, then take 30 across.',
                                ],
                                [
                                    'key' => 'q2',
                                    'equation' => '4x + 7 = 18/3 − [−4 − 5]',
                                    'hint' => 'First 18/3. Then [−4 − 5] is a negative number. Minus a negative adds. Then take +7 across and divide by 4.',
                                ],
                                [
                                    'key' => 'q3',
                                    'equation' => '16 − 2x = 2 × (9 − 4) + [−8]',
                                    'hint' => 'Brackets first: 9 − 4. Then multiply by 2. Then add [−8]. After the right-hand side is one number, take 16 across and divide by 2.',
                                ],
                                [
                                    'key' => 'q4',
                                    'equation' => '5x − 10 = 3 × [1 − 4] + 16/4',
                                    'hint' => '[1 − 4] first, then × 3. Next 16/4. Combine those two numbers. Then take −10 across and divide by 5.',
                                ],
                                [
                                    'key' => 'q5',
                                    'equation' => '21 − 3x = 36/9 − [−3 − 6] + [−4]',
                                    'hint' => '36/9 first. Then [−3 − 6]. Minus that bracket (minus a negative adds), then +[−4]. Finish the right-hand side, take 21 across, divide by 3.',
                                ],
                            ],
                        ],
                        'check' => [
                            'q1' => '5',
                            'q2' => '2',
                            'q3' => '7',
                            'q4' => '1',
                            'q5' => '4',
                        ],
                    ]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $oldId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-3')->value('id');
        if ($oldId) {
            DB::table('mmm_cards')->where('id', $oldId)->delete();
        }
    }

    public function down(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-2')->value('id');
        if ($cardId) {
            DB::table('mmm_card_exercises')
                ->where('mmm_card_id', $cardId)
                ->where('sort_order', 4)
                ->delete();
        }
    }
};
