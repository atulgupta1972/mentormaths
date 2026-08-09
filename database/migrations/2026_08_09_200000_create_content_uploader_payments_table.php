<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_uploader_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_upload_task_id')->constrained('content_upload_tasks')->cascadeOnDelete();
            $table->unsignedInteger('amount_inr');
            $table->date('paid_on');
            $table->string('method', 32)->default('upi');
            $table->string('upi_or_reference', 255);
            $table->text('notes')->nullable();
            $table->foreignId('paid_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();

            $table->unique('content_upload_task_id');
            $table->index('paid_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_uploader_payments');
    }
};
