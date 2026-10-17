<?php

use App\Support\ConceptBranch;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chapter_heads') || ! Schema::hasColumn('chapter_heads', 'branch')) {
            return;
        }

        // Explicit fix for the known mis-tag (Geometry ← "line" in Linear).
        DB::table('chapter_heads')
            ->where('name', 'like', '%Linear Equation%')
            ->update(['branch' => 'algebra']);

        DB::table('chapter_heads')
            ->where('name', 'like', '%Simple Equation%')
            ->update(['branch' => 'algebra']);

        // Re-guess any head still sitting under geometry that clearly belongs to algebra.
        $rows = DB::table('chapter_heads')
            ->where('branch', 'geometry')
            ->select('id', 'name')
            ->get();

        foreach ($rows as $row) {
            $guess = ConceptBranch::guessFromName((string) $row->name);
            if ($guess === 'algebra') {
                DB::table('chapter_heads')
                    ->where('id', $row->id)
                    ->update(['branch' => 'algebra']);
            }
        }
    }

    public function down(): void
    {
        // Irreversible data fix — no-op.
    }
};
