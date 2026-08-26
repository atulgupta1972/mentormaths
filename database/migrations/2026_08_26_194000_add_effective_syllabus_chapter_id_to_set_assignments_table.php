<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->foreignId('effective_syllabus_chapter_id')
                ->nullable()
                ->after('exam_plan_id')
                ->constrained('syllabus_chapters')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('effective_syllabus_chapter_id');
        });
    }
};
