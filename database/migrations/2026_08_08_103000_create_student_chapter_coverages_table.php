<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_chapter_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->timestamp('studied_at')->nullable();
            $table->timestamp('marked_under_study_at')->nullable();
            $table->timestamps();

            $table->unique(['student_enrollment_id', 'syllabus_chapter_id'], 'student_chapter_coverages_unique');
            $table->index(['student_enrollment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_chapter_coverages');
    }
};
