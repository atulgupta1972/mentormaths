<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mensuration_match_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->boolean('perimeter_area_enabled')->default(true);
            $table->boolean('volume_enabled')->default(false);
            $table->timestamps();
        });

        Schema::create('mensuration_match_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_enrollment_id')->nullable()->constrained()->nullOnDelete();
            $table->date('drill_date');
            $table->string('board', 32);
            $table->string('status', 20)->default('ready');
            $table->unsignedSmallInteger('total_items')->default(0);
            $table->unsignedSmallInteger('correct_count')->default(0);
            $table->json('item_keys')->nullable();
            $table->json('answers')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['student_id', 'drill_date', 'board']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mensuration_match_sessions');
        Schema::dropIfExists('mensuration_match_settings');
    }
};
