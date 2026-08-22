<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_messages', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 64);
            $table->string('to_mobile', 20);
            $table->string('recipient_label')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->text('message_body');
            $table->string('template_name')->nullable();
            $table->string('meta_message_id')->nullable();
            $table->string('status', 16);
            $table->string('error')->nullable();
            $table->string('driver', 32);
            $table->timestamps();

            $table->index(['created_at', 'status']);
            $table->index(['student_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_messages');
    }
};
