<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_concept_path_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('textbook_chapter_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 32)->default('in_progress');
            $table->unsignedSmallInteger('cards_total')->default(0);
            $table->unsignedSmallInteger('cards_completed')->default(0);
            $table->unsignedSmallInteger('current_card_index')->default(0);
            $table->json('events')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'textbook_chapter_id'], 'student_concept_path_user_chapter_unique');
            $table->index(['syllabus_chapter_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_concept_path_progress');
    }
};
