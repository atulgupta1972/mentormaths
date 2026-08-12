<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->foreignId('fill_blank_worksheet_id')->nullable()->after('written_worksheet_id')
                ->constrained('worksheets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('textbook_chapters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fill_blank_worksheet_id');
        });
    }
};
