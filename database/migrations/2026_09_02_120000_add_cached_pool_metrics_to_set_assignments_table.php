<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->json('cached_pool_metrics')->nullable()->after('notes');
            $table->timestamp('cached_metrics_at')->nullable()->after('cached_pool_metrics');
        });
    }

    public function down(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            $table->dropColumn(['cached_pool_metrics', 'cached_metrics_at']);
        });
    }
};
