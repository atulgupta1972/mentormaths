<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Study-plan / self-open assignments should not carry teacher deadlines.
 * Due dates remain only when a teacher/admin assigns with a target date (email / WhatsApp).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('set_assignments')
            ->whereNotNull('due_date')
            ->where(function ($query) {
                $query->where('notes', 'like', 'Auto-assigned%')
                    ->orWhere('notes', 'like', 'Self-assigned%')
                    ->orWhere('notes', 'Auto revision after 100% score')
                    ->orWhere('notes', 'Revision mentoring');
            })
            ->update(['due_date' => null]);
    }

    public function down(): void
    {
        // Irreversible data cleanup — due dates were not meaningful teacher deadlines.
    }
};
