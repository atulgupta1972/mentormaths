<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->string('purpose', 20)->default('standard')->after('status');
            $table->foreignId('catch_up_parent_worksheet_id')
                ->nullable()
                ->after('purpose')
                ->constrained('worksheets')
                ->nullOnDelete();
            $table->foreignId('catch_up_for_enrollment_id')
                ->nullable()
                ->after('catch_up_parent_worksheet_id')
                ->constrained('student_enrollments')
                ->nullOnDelete();
            $table->json('catch_up_source_question_ids')->nullable()->after('catch_up_for_enrollment_id');
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('catch_up_parent_worksheet_id');
            $table->dropConstrainedForeignId('catch_up_for_enrollment_id');
            $table->dropColumn(['purpose', 'catch_up_source_question_ids']);
        });
    }
};
