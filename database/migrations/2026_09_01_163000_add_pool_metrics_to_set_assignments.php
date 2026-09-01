<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('set_assignments', 'pool_metrics')) {
                $table->json('pool_metrics')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('set_assignments', 'pool_metrics_updated_at')) {
                $table->timestamp('pool_metrics_updated_at')
                    ->nullable()
                    ->after('pool_metrics');
            }
        });

        try {
            Schema::table('set_assignments', function (Blueprint $table) {
                $table->index('pool_metrics_updated_at', 'set_assignments_pool_scored_idx');
            });
        } catch (Throwable) {
            // Index already exists (partial prior migrate).
        }
    }

    public function down(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            try {
                $table->dropIndex('set_assignments_pool_scored_idx');
            } catch (Throwable) {
            }

            if (Schema::hasColumn('set_assignments', 'pool_metrics_updated_at')) {
                $table->dropColumn('pool_metrics_updated_at');
            }

            if (Schema::hasColumn('set_assignments', 'pool_metrics')) {
                $table->dropColumn('pool_metrics');
            }
        });
    }
};
