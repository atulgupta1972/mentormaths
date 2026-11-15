<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('direction', 8);
            $table->string('mobile', 20);
            $table->string('contact_name')->nullable();
            $table->string('recipient_label')->nullable();
            $table->foreignId('student_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->string('meta_message_id')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->string('status', 16);
            $table->string('error')->nullable();
            $table->timestamps();

            $table->unique('meta_message_id');
            $table->index(['mobile', 'id']);
            $table->index(['direction', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_inbox_messages');
    }
};
