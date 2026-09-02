<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_chapter_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_enrollment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->json('performance');
            $table->timestamp('metrics_updated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['student_enrollment_id', 'syllabus_chapter_id'],
                'student_chapter_metrics_unique',
            );
            $table->index(['student_enrollment_id', 'metrics_updated_at'], 'scm_enrollment_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_chapter_metrics');
    }
};
