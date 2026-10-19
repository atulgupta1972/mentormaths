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

        DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 7)
            ->update([
                'answers' => json_encode([
                    'mode' => 'variable',
                    'variable_tip' => 'We do not know how many were correct. Let x = number of correct answers. Then wrong answers = 20 − x. Fill the table using x, write the equation from the Total column, then find x.',
                    'x_prompt' => 'How many correct? (value of x)',
                    'correct' => [
                        'marks' => '2',
                        'count' => ['x', 'X'],
                        'total' => ['2x', '2X', '2*x'],
                    ],
                    'wrong' => [
                        'marks' => '-1',
                        'count' => ['20-x', '20 - x', '20-X'],
                        'total' => [
                            '-20+x',
                            'x-20',
                            '-(20-x)',
                            '-1*(20-x)',
                            '-1(20-x)',
                            '(-1)*(20-x)',
                        ],
                    ],
                    'grand_total' => '25',
                    'equation' => [
                        '2x+(-20+x)=25',
                        '2x-20+x=25',
                        '3x-20=25',
                        '2x+(x-20)=25',
                        '2x+x-20=25',
                        '2x-1*(20-x)=25',
                        '2x+-1*(20-x)=25',
                        '2x+(-1*(20-x))=25',
                        '25=3x-20',
                        '25=2x+(-20+x)',
                    ],
                    'x_value' => '15',
                ], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // no-op
    }
};
