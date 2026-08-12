<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_correction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('worksheet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guided_attempt_question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('written_submission_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 32);
            $table->string('failure_reason', 32);
            $table->string('status', 16)->default('pending');
            $table->timestamp('first_failure_at');
            $table->timestamp('corrected_at')->nullable();
            $table->string('corrected_in', 32)->nullable();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['student_id', 'worksheet_id', 'status']);
            $table->index(['syllabus_chapter_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_correction_items');
    }
};
