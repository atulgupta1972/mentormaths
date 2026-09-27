<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_chapter_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('term');
            $table->timestamps();

            $table->unique(['student_enrollment_id', 'syllabus_chapter_id'], 'student_chapter_terms_unique');
            $table->index(['student_enrollment_id', 'term']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_chapter_terms');
    }
};
