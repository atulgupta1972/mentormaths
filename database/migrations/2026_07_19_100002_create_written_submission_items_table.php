<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('written_submission_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('written_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('question_number');
            $table->text('extracted_answer')->nullable();
            $table->text('step_feedback')->nullable();
            $table->unsignedSmallInteger('score')->default(0);
            $table->unsignedSmallInteger('max_score')->default(1);
            $table->boolean('is_correct')->default(false);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->boolean('needs_review')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('written_submission_items');
    }
};
