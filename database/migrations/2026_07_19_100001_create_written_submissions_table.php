<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('written_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_assignment_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('uploaded');
            $table->json('upload_paths')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_score')->nullable();
            $table->text('ai_summary')->nullable();
            $table->text('grading_error')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('written_submissions');
    }
};
