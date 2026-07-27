<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->string('formula_drill_scope', 32)->nullable()->after('bank_purpose');
        });

        Schema::create('formula_drill_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('drill_date');
            $table->string('status', 32)->default('in_progress');
            $table->unsignedSmallInteger('questions_total')->default(10);
            $table->unsignedSmallInteger('questions_completed')->default(0);
            $table->unsignedInteger('pool_size')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'drill_date']);
        });

        Schema::create('formula_drill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('formula_drill_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('status', 32)->default('pending');
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->unsignedTinyInteger('failure_count')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['formula_drill_session_id', 'question_id']);
        });

        Schema::create('formula_question_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('total_failures')->default(0);
            $table->unsignedInteger('times_shown')->default(0);
            $table->unsignedInteger('times_correct')->default(0);
            $table->unsignedInteger('times_exhausted')->default(0);
            $table->boolean('needs_review')->default(false);
            $table->date('last_shown_date')->nullable();
            $table->timestamp('last_correct_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('formula_question_stats');
        Schema::dropIfExists('formula_drill_items');
        Schema::dropIfExists('formula_drill_sessions');

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('formula_drill_scope');
        });
    }
};
