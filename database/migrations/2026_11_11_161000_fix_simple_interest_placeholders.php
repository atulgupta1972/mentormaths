<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $cardId = DB::table('mmm_cards')->where('slug', 'banking-simple-interest')->value('id');
        if (! $cardId) {
            return;
        }

        $rows = DB::table('mmm_card_exercises')->where('mmm_card_id', $cardId)->get(['id', 'answers']);
        foreach ($rows as $row) {
            $answers = json_decode((string) $row->answers, true);
            if (! is_array($answers)) {
                continue;
            }
            $given = $answers['play']['given_rows'] ?? null;
            if (! is_array($given)) {
                continue;
            }
            $changed = false;
            foreach ($given as $i => $item) {
                if (is_array($item) && array_key_exists('placeholder', $item)) {
                    unset($answers['play']['given_rows'][$i]['placeholder']);
                    $changed = true;
                }
            }
            if ($changed) {
                DB::table('mmm_card_exercises')->where('id', $row->id)->update([
                    'answers' => json_encode($answers, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        //
    }
};
