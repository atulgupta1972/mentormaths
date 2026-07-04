<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->date('exam_date');
            $table->string('title');
            $table->string('exam_type', 30)->default('unit_test');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('planned');
            $table->timestamps();

            $table->index(['student_enrollment_id', 'exam_date']);
            $table->index(['exam_date', 'status']);
        });

        Schema::create('exam_plan_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_plan_id', 'syllabus_chapter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_plan_chapters');
        Schema::dropIfExists('exam_plans');
    }
};
