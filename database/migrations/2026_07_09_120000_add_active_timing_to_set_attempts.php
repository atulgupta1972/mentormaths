<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->unsignedInteger('active_seconds')->default(0)->after('started_at');
            $table->timestamp('active_session_started_at')->nullable()->after('active_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->dropColumn(['active_seconds', 'active_session_started_at']);
        });
    }
};
