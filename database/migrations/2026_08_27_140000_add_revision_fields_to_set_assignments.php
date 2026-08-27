<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            if (! Schema::hasColumn('set_assignments', 'parent_assignment_id')) {
                $table->foreignId('parent_assignment_id')
                    ->nullable()
                    ->after('worksheet_id')
                    ->constrained('set_assignments')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('set_assignments', 'revision_number')) {
                $table->unsignedSmallInteger('revision_number')
                    ->default(0)
                    ->after('parent_assignment_id');
            }
        });

        // Short names — MySQL index identifier limit is 64 chars.
        try {
            Schema::table('set_assignments', function (Blueprint $table) {
                $table->index(
                    ['student_enrollment_id', 'worksheet_id', 'revision_number'],
                    'set_assignments_rev_lookup_idx',
                );
            });
        } catch (\Throwable) {
            // Index already exists (partial prior migrate).
        }

        try {
            Schema::table('set_assignments', function (Blueprint $table) {
                $table->index(['parent_assignment_id'], 'set_assignments_parent_idx');
            });
        } catch (\Throwable) {
            // Index already exists.
        }
    }

    public function down(): void
    {
        Schema::table('set_assignments', function (Blueprint $table) {
            try {
                $table->dropIndex('set_assignments_parent_idx');
            } catch (\Throwable) {
            }

            try {
                $table->dropIndex('set_assignments_rev_lookup_idx');
            } catch (\Throwable) {
            }

            if (Schema::hasColumn('set_assignments', 'parent_assignment_id')) {
                $table->dropConstrainedForeignId('parent_assignment_id');
            }

            if (Schema::hasColumn('set_assignments', 'revision_number')) {
                $table->dropColumn('revision_number');
            }
        });
    }
};
