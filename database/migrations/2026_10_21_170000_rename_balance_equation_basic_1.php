<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('mmm_cards')->where('slug', 'balance-the-equation')->update([
            'title' => 'Balance the equation — Basic 1',
            'heading' => 'Balance the equation — Basic 1',
            'method_blurb' => 'An equation is a balance scale: left side = right side. Whatever you add or subtract on one side, do the same on the other so it stays level. To free x, add the opposite of the number stuck to x, cancel, then read x. This card is + and − with integers. Next, Basic 2 uses terms and divide.',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('mmm_cards')->where('slug', 'balance-the-equation')->update([
            'title' => 'Balance the equation',
            'heading' => 'Balance the equation',
            'method_blurb' => 'An equation is a balance scale: left side = right side. Whatever you add or subtract on one side, do the same on the other so it stays level. To free x, add the opposite of the number stuck to x, cancel, then read x.',
            'updated_at' => now(),
        ]);
    }
};
