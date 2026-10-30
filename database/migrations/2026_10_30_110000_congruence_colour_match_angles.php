<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'congruence-sas')->value('id');
        if (! $cardId) {
            return;
        }

        $now = now();
        $exercises = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->orderBy('sort_order')->get();

        foreach ($exercises as $exercise) {
            $answers = json_decode($exercise->answers, true) ?: [];
            $steps = $answers['play']['steps'] ?? [];
            $orderIndex = collect($steps)->search(fn ($step) => ($step['key'] ?? '') === 'order');
            if ($orderIndex === false) {
                continue;
            }

            if ((int) $exercise->sort_order === 1) {
                $steps[$orderIndex]['prompt'] = 'Sketch △ABC as given. Write P, Q, R and the side lengths. Click the equal angle on △ABC, then click the matching corner on the second triangle so both get the same colour.';
                $answers['check']['order']['angle_pairs'] = ['A' => 'P'];
                unset($answers['check']['order']['angle']);
                $answers['check']['order']['ok'] = 'P on A, Q on B, R on C. 5 cm on AB, 6 cm on AC, and ∠A matches ∠P (same colour).';
                $answers['check']['order']['miss'] = 'Write P, Q, R. Put 5 on AB and 6 on AC. Click ∠A on △ABC, then the matching corner (P) on the second triangle.';
            }

            $answers['play']['steps'] = array_values($steps);

            DB::table('mmm_card_exercises')->where('id', $exercise->id)->update([
                'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Content-only update.
    }
};
