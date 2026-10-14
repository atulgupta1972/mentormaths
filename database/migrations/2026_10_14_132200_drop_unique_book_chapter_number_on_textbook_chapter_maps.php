<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('textbook_chapter_maps', function (Blueprint $table) {
            $table->dropUnique(['textbook_id', 'book_chapter_number']);
        });
    }

    public function down(): void
    {
        Schema::table('textbook_chapter_maps', function (Blueprint $table) {
            $table->unique(['textbook_id', 'book_chapter_number']);
        });
    }
};
