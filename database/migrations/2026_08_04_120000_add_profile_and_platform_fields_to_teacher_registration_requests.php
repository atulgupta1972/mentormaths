<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->string('gender', 30)->nullable()->after('mobile');
            $table->date('date_of_birth')->nullable()->after('gender');
            $table->string('monitoring_platform_name')->nullable()->after('bio');
            $table->string('platform_usage_scope', 30)->nullable()->after('monitoring_platform_name');
            $table->json('current_tool_features')->nullable()->after('platform_usage_scope');
            $table->text('platform_experience_notes')->nullable()->after('current_tool_features');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_registration_requests', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'date_of_birth',
                'monitoring_platform_name',
                'platform_usage_scope',
                'current_tool_features',
                'platform_experience_notes',
            ]);
        });
    }
};
