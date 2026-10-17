<?php

use App\Support\ConceptBranch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_heads', function (Blueprint $table) {
            $table->string('branch', 40)->default('other')->after('name');
            $table->index('branch');
        });

        if (Schema::hasTable('chapter_heads')) {
            $rows = DB::table('chapter_heads')->select('id', 'name')->get();
            foreach ($rows as $row) {
                DB::table('chapter_heads')
                    ->where('id', $row->id)
                    ->update(['branch' => ConceptBranch::guessFromName((string) $row->name)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('chapter_heads', function (Blueprint $table) {
            $table->dropIndex(['branch']);
            $table->dropColumn('branch');
        });
    }
};
