<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            $table->string('practice_line', 32)->default('standard')->after('code');
            $table->string('source_ref', 64)->nullable()->after('practice_line');
        });
    }

    public function down(): void
    {
        Schema::table('textbooks', function (Blueprint $table) {
            $table->dropColumn(['practice_line', 'source_ref']);
        });
    }
};
