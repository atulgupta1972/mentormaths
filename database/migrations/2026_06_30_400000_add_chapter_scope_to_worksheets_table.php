<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->foreignId('syllabus_chapter_id')->nullable()->after('syllabus_topic_id')->constrained()->nullOnDelete();
            $table->string('scope', 20)->default('topic')->after('syllabus_chapter_id');
        });

        DB::table('worksheets')->whereNotNull('syllabus_topic_id')->update(['scope' => 'topic']);
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('syllabus_chapter_id');
            $table->dropColumn('scope');
        });
    }
};
