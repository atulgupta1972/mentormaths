<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_rate_cards', function (Blueprint $table) {
            $table->string('rate_basis', 20)->default('per_set')->after('content_type');
        });

        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->string('rate_basis', 20)->default('per_set')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('content_upload_tasks', function (Blueprint $table) {
            $table->dropColumn('rate_basis');
        });

        Schema::table('content_rate_cards', function (Blueprint $table) {
            $table->dropColumn('rate_basis');
        });
    }
};
