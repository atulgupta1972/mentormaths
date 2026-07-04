<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_plan_topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_topic_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['exam_plan_id', 'syllabus_topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_plan_topics');
    }
};
