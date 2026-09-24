<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            if (! Schema::hasColumn('textbook_chapters', 'concept_path_last_played_at')) {
                $table->timestamp('concept_path_last_played_at')->nullable()->after('concept_path_approved_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            if (Schema::hasColumn('textbook_chapters', 'concept_path_last_played_at')) {
                $table->dropColumn('concept_path_last_played_at');
            }
        });
    }
};
