<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grade_level_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->foreignId('copied_from_id')->nullable()->constrained('syllabus_versions')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['board_id', 'grade_level_id', 'subject_id', 'academic_year_id'],
                'syllabus_versions_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_versions');
    }
};
