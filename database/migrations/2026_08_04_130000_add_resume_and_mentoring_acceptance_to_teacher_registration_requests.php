<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->string('resume_path')->nullable()->after('bio');
            $table->string('resume_original_name')->nullable()->after('resume_path');
            $table->boolean('agreed_to_mentoring_program')->default(false)->after('interested_in_doubt_solving');
            $table->timestamp('mentoring_agreed_at')->nullable()->after('agreed_to_mentoring_program');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->dropColumn([
                'resume_path',
                'resume_original_name',
                'agreed_to_mentoring_program',
                'mentoring_agreed_at',
            ]);
        });
    }
};
