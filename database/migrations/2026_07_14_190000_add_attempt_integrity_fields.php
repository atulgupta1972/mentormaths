<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('tab_leave_count')->default(0)->after('active_session_started_at');
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->boolean('protect_test_attempts')->default(true)->after('is_active');
            $table->boolean('protect_practice_attempts')->default(true)->after('protect_test_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->dropColumn('tab_leave_count');
        });

        Schema::table('grade_levels', function (Blueprint $table) {
            $table->dropColumn(['protect_test_attempts', 'protect_practice_attempts']);
        });
    }
};
