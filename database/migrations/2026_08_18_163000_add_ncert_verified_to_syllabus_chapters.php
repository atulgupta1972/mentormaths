<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('syllabus_chapters', function (Blueprint $table) {
            $table->boolean('ncert_verified')->default(false)->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_chapters', function (Blueprint $table) {
            $table->dropColumn('ncert_verified');
        });
    }
};
