<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->string('mode', 20)->default('batch')->after('attempt_number');
            $table->unsignedSmallInteger('current_question_index')->default(0)->after('mode');
            $table->unsignedSmallInteger('first_try_correct_count')->nullable()->after('max_score');
            $table->unsignedSmallInteger('corrected_after_help_count')->nullable()->after('first_try_correct_count');
            $table->unsignedSmallInteger('given_up_count')->nullable()->after('corrected_after_help_count');
        });

        Schema::create('guided_attempt_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('phase', 20)->default('pending');
            $table->unsignedTinyInteger('wrong_before_explanation')->default(0);
            $table->boolean('first_try_correct')->default(false);
            $table->boolean('corrected_after_help')->default(false);
            $table->boolean('gave_up')->default(false);
            $table->foreignId('final_option_id')->nullable()->constrained('question_options')->nullOnDelete();
            $table->boolean('final_is_correct')->default(false);
            $table->timestamps();

            $table->unique(['set_attempt_id', 'question_id']);
        });

        Schema::create('question_resolution_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guided_attempt_question_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->timestamp('gave_up_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['student_enrollment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_resolution_items');
        Schema::dropIfExists('guided_attempt_questions');

        Schema::table('set_attempts', function (Blueprint $table) {
            $table->dropColumn([
                'mode',
                'current_question_index',
                'first_try_correct_count',
                'corrected_after_help_count',
                'given_up_count',
            ]);
        });
    }
};
