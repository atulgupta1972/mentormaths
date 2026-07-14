<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('obtained_marks')->nullable()->after('notes');
            $table->unsignedSmallInteger('total_marks')->nullable()->after('obtained_marks');
            $table->timestamp('marks_entered_at')->nullable()->after('total_marks');
        });
    }

    public function down(): void
    {
        Schema::table('exam_plans', function (Blueprint $table) {
            $table->dropColumn(['obtained_marks', 'total_marks', 'marks_entered_at']);
        });
    }
};
