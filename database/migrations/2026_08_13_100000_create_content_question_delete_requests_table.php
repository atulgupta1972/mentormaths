<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_question_delete_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_upload_task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('textbook_chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('item_index');
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question_text')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 24)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_question_delete_requests');
    }
};
