<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->foreignId('exam_plan_id')
                ->nullable()
                ->after('catch_up_source_question_ids')
                ->constrained('exam_plans')
                ->nullOnDelete();
            $table->index(['exam_plan_id', 'purpose']);
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_plan_id');
        });
    }
};
