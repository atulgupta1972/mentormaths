<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->json('mcq_worksheet_ids')->nullable()->after('mcq_worksheet_id');
        });
    }

    public function down(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->dropColumn('mcq_worksheet_ids');
        });
    }
};
