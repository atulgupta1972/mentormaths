<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('question_resolution_items', function (Blueprint $table) {
            $table->string('clearance_method', 20)->nullable()->after('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::table('question_resolution_items', function (Blueprint $table) {
            $table->dropColumn('clearance_method');
        });
    }
};
