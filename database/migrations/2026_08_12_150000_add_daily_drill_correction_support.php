<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('formula_drill_items', function (Blueprint $table) {
            $table->string('round', 16)->default('main')->after('sort_order');
            $table->foreignId('practice_correction_item_id')->nullable()->after('question_id')->constrained()->nullOnDelete();
        });

        Schema::table('basics_drill_items', function (Blueprint $table) {
            $table->foreignId('question_id')->nullable()->after('basics_drill_session_id')->constrained()->nullOnDelete();
            $table->foreignId('practice_correction_item_id')->nullable()->after('question_id')->constrained()->nullOnDelete();
            $table->foreignId('source_formula_drill_item_id')->nullable()->after('practice_correction_item_id')
                ->constrained('formula_drill_items')->nullOnDelete();
            $table->unsignedBigInteger('source_basics_drill_item_id')->nullable()->after('source_formula_drill_item_id');
        });

        Schema::table('basics_drill_items', function (Blueprint $table) {
            $table->foreign('source_basics_drill_item_id')
                ->references('id')
                ->on('basics_drill_items')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('basics_drill_items', function (Blueprint $table) {
            $table->dropForeign(['source_basics_drill_item_id']);
            $table->dropConstrainedForeignId('source_formula_drill_item_id');
            $table->dropConstrainedForeignId('practice_correction_item_id');
            $table->dropConstrainedForeignId('question_id');
            $table->dropColumn('source_basics_drill_item_id');
        });

        Schema::table('formula_drill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('practice_correction_item_id');
            $table->dropColumn('round');
        });
    }
};
