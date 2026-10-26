<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mmm_cards', function (Blueprint $table) {
            $table->foreignId('chapter_head_id')
                ->nullable()
                ->after('heading')
                ->constrained('chapter_heads')
                ->nullOnDelete();
            $table->string('sub_heading', 120)->nullable()->after('chapter_head_id');
        });

        Schema::create('mmm_card_grade_level', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mmm_card_id')->constrained('mmm_cards')->cascadeOnDelete();
            $table->foreignId('grade_level_id')->constrained('grade_levels')->cascadeOnDelete();
            $table->unique(['mmm_card_id', 'grade_level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mmm_card_grade_level');

        Schema::table('mmm_cards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chapter_head_id');
            $table->dropColumn('sub_heading');
        });
    }
};
