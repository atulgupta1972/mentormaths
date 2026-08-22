<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_issue_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('set_assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_attempt_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('guided_attempt_question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('formula_drill_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('context', 32);
            $table->string('reason', 48)->default('misprint_incomplete');
            $table->string('status', 32)->default('pending_admin');
            $table->timestamp('reported_at');
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'reported_at']);
            $table->index(['student_id', 'status']);
            $table->index(['question_id', 'status']);
        });

        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->boolean('reported_issue')->default(false)->after('gave_up');
        });
    }

    public function down(): void
    {
        Schema::table('guided_attempt_questions', function (Blueprint $table) {
            $table->dropColumn('reported_issue');
        });

        Schema::dropIfExists('question_issue_reports');
    }
};
