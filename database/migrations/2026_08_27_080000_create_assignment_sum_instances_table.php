<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_sum_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_assignment_id')->constrained('set_assignments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('worksheet_id')->constrained('worksheets')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('questions')->cascadeOnDelete();
            $table->foreignId('source_instance_id')
                ->nullable()
                ->constrained('assignment_sum_instances')
                ->nullOnDelete();
            $table->unsignedSmallInteger('generation')->default(0);
            $table->string('status', 20)->default('pending'); // pending|correct|wrong
            $table->foreignId('set_attempt_id')->nullable()->constrained('set_attempts')->nullOnDelete();
            $table->foreignId('guided_attempt_question_id')->nullable()->constrained('guided_attempt_questions')->nullOnDelete();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamps();

            $table->index(['set_assignment_id', 'status']);
            $table->index(['worksheet_id', 'student_id']);
            $table->index(['source_instance_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_sum_instances');
    }
};
