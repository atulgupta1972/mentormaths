<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->json('fill_blank_worksheet_ids')->nullable()->after('fill_blank_worksheet_id');
            $table->json('written_worksheet_ids')->nullable()->after('written_worksheet_id');
        });
    }

    public function down(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->dropColumn(['fill_blank_worksheet_ids', 'written_worksheet_ids']);
        });
    }
};
