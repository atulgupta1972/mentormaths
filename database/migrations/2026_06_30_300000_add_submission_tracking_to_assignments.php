<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->timestamp('reassigned_at')->nullable()->after('assigned_at');
        });

        Schema::table('set_attempts', function (Blueprint $table) {
            $table->string('submission_timing', 20)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->dropColumn('submission_timing');
        });

        Schema::table('set_assignments', function (Blueprint $table) {
            $table->dropColumn('reassigned_at');
        });
    }
};
