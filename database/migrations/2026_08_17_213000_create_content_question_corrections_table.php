<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_question_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_upload_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('question_number')->nullable();
            $table->text('question_text')->nullable();
            $table->text('remark')->nullable();
            $table->string('source', 32)->default('admin_return');
            $table->string('status', 24)->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['content_upload_task_id', 'status']);
            $table->index(['question_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_question_corrections');
    }
};
