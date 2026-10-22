<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'balance-equation-basic-4')->value('id');
        if (! $cardId) {
            return;
        }

        $row = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 3)
            ->first();
        if (! $row) {
            return;
        }

        $answers = json_decode($row->answers, true);
        $first = $answers['play']['steps'][0]['type'] ?? '';
        if ($first === 'copy_line') {
            return;
        }

        DB::table('mmm_card_exercises')->where('id', $row->id)->update([
            'question_text' => 'Solve 2x − [−4 − 9] + 12 = 3x − 5x + (−20 + 40). Each line simplifies both sides.',
            'answers' => json_encode($this->answers()),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        //
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        $line = function (string $key, string $hint, string $miss, array $parts, ?string $from = null): array {
            return array_filter([
                'key' => $key,
                'type' => 'copy_line',
                'from' => $from,
                'hint' => $hint,
                'miss' => $miss,
                'parts' => $parts,
            ], fn ($value) => $value !== null);
        };

        return [
            'mode' => 'balance',
            'variable_tip' => 'Write the next line of the copy with (=). On each line, simplify the left and the right together. Brackets first, on both sides. Then move the x-terms. A number changes sign when it crosses.',
            'play' => [
                'given_left' => '2x − [−4 − 9] + 12',
                'given_right' => '3x − 5x + (−20 + 40)',
                'scale_tip' => 'Each step is the next line. Do the left side and the right side on that same line.',
                'steps' => [
                    $line('open', 'Solve both sides. Left: inside [−4 − 9]. Right: 3x − 5x, and −20 + 40.', 'Left bracket is −13. Right x-terms are −2x. The number on the right is 20.', [
                        ['text' => '2x − ('],
                        ['box' => 'br', 'label' => 'Inside the left bracket'],
                        ['text' => ') + 12'],
                        ['eq' => true],
                        ['box' => 'rx', 'label' => '3x − 5x'],
                        ['text' => '+ ('],
                        ['box' => 'rnum', 'label' => '−20 + 40'],
                        ['text' => ')'],
                    ]),
                    $line('sign', '−(−13) changes sign. The right side stays −2x + 20.', 'A minus in front of −13 becomes +13.', [
                        ['text' => '2x +'],
                        ['box' => 'sign', 'label' => '−(−13)'],
                        ['text' => '+ 12'],
                        ['eq' => true],
                        ['text' => '−2x + 20'],
                    ], '2x − (−13) + 12 = −2x + (20)'),
                    $line('addn', 'Add the numbers on the left. The right side stays −2x + 20.', '13 + 12 = 25.', [
                        ['text' => '2x +'],
                        ['box' => 'addn', 'label' => '13 + 12'],
                        ['eq' => true],
                        ['text' => '−2x + 20'],
                    ], '2x + 13 + 12 = −2x + 20'),
                    $line('move', '−2x crosses to the left and becomes +2x. +25 crosses to the right and becomes −25.', 'Write 2x on the left and 25 on the right.', [
                        ['text' => '2x +'],
                        ['box' => 'moved_x', 'label' => 'x-term that crossed'],
                        ['eq' => true],
                        ['text' => '20 −'],
                        ['box' => 'moved_n', 'label' => 'number that crossed'],
                    ], '2x + 25 = −2x + 20'),
                    $line('simp', 'Simplify both sides. 2x + 2x on the left. 20 − 25 on the right. Mind the sign.', 'Left is 4x. Right is −5.', [
                        ['box' => 'coef', 'label' => '2x + 2x'],
                        ['eq' => true],
                        ['box' => 'rhs_n', 'label' => '20 − 25'],
                    ], '2x + 2x = 20 − 25'),
                    $line('x', 'Divide both sides by 4. A fraction is fine.', '−5 ÷ 4 = −5/4.', [
                        ['text' => 'x'],
                        ['eq' => true],
                        ['box' => 'x', 'label' => 'Value of x'],
                    ], '4x = −5'),
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
        ];
    }
};
