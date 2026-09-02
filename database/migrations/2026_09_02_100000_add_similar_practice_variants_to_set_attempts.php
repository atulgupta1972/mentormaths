<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->json('similar_practice_variants')->nullable()->after('submission_timing');
        });
    }

    public function down(): void
    {
        Schema::table('set_attempts', function (Blueprint $table) {
            $table->dropColumn('similar_practice_variants');
        });
    }
};
