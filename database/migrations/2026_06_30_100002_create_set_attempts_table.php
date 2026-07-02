<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('set_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedSmallInteger('max_score')->nullable();
            $table->unsignedInteger('time_seconds')->nullable();
            $table->string('status', 20)->default('in_progress');
            $table->timestamps();

            $table->unique(['set_assignment_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('set_attempts');
    }
};
