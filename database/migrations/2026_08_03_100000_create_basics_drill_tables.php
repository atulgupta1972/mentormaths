<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('basics_drill_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('tables_enabled')->default(true);
            $table->boolean('squares_enabled')->default(true);
            $table->boolean('cubes_enabled')->default(true);
            $table->unsignedTinyInteger('table_from')->default(2);
            $table->unsignedTinyInteger('table_to')->default(19);
            $table->unsignedTinyInteger('multiplier_from')->default(2);
            $table->unsignedTinyInteger('multiplier_to')->default(9);
            $table->unsignedTinyInteger('square_from')->default(2);
            $table->unsignedTinyInteger('square_to')->default(30);
            $table->unsignedTinyInteger('cube_from')->default(2);
            $table->unsignedTinyInteger('cube_to')->default(13);
            $table->unsignedTinyInteger('squares_per_day')->default(5);
            $table->unsignedTinyInteger('cubes_per_day')->default(3);
            $table->unsignedTinyInteger('seconds_per_blank')->default(5);
            $table->timestamps();
        });

        Schema::create('basics_drill_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('next_table')->default(2);
            $table->unsignedTinyInteger('square_batch_start')->default(2);
            $table->unsignedTinyInteger('cube_batch_start')->default(2);
            $table->timestamps();

            $table->unique('student_id');
        });

        Schema::create('basics_drill_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('drill_date');
            $table->string('status', 20)->default('in_progress');
            $table->string('phase', 30)->default('table_show');
            $table->unsignedTinyInteger('table_number')->nullable();
            $table->unsignedTinyInteger('square_batch_start')->nullable();
            $table->unsignedTinyInteger('cube_batch_start')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'drill_date']);
        });

        Schema::create('basics_drill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('basics_drill_session_id')->constrained()->cascadeOnDelete();
            $table->string('fact_type', 10);
            $table->string('fact_key', 20);
            $table->unsignedTinyInteger('operand_a');
            $table->unsignedTinyInteger('operand_b')->default(0);
            $table->unsignedInteger('correct_answer');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('round', 10)->default('main');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('response_ms')->nullable();
            $table->timestamps();
        });

        Schema::create('basics_fact_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('fact_type', 10);
            $table->string('fact_key', 20);
            $table->unsignedInteger('times_shown')->default(0);
            $table->unsignedInteger('times_correct')->default(0);
            $table->unsignedInteger('times_failed')->default(0);
            $table->boolean('needs_review')->default(false);
            $table->date('last_shown_date')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'fact_type', 'fact_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('basics_fact_stats');
        Schema::dropIfExists('basics_drill_items');
        Schema::dropIfExists('basics_drill_sessions');
        Schema::dropIfExists('basics_drill_progress');
        Schema::dropIfExists('basics_drill_settings');
    }
};
