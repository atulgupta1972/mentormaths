<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_topic_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('mcq');
            $table->text('question_text');
            $table->text('explanation')->nullable();
            $table->string('difficulty', 20)->nullable();
            $table->string('source', 20)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['syllabus_topic_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
