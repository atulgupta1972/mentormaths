<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->foreignId('exam_plan_id')
                ->nullable()
                ->after('worksheet_id')
                ->constrained()
                ->nullOnDelete();

            $table->index(['exam_plan_id', 'student_enrollment_id']);
        });
    }

    public function down(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('exam_plan_id');
        });
    }
};
