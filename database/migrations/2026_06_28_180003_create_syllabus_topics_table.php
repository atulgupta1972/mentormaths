<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('learning_outcomes')->nullable();
            $table->string('difficulty', 20)->nullable();
            $table->unsignedSmallInteger('planned_periods')->nullable();
            $table->text('remarks')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_topics');
    }
};
