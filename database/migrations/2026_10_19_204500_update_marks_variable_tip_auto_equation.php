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

        $row = DB::table('mmm_card_exercises')
            ->where('mmm_card_id', $cardId)
            ->where('sort_order', 7)
            ->first();

        if (! $row) {
            return;
        }

        $answers = json_decode($row->answers, true) ?: [];
        $answers['variable_tip'] = 'We do not know how many were correct. Let x = number of correct answers. Then wrong answers = 20 − x. Fill Marks, No. of Ques, and Total (using x). The equation is made automatically from the Total column — then find x.';

        DB::table('mmm_card_exercises')
            ->where('id', $row->id)
            ->update([
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // no-op
    }
};
