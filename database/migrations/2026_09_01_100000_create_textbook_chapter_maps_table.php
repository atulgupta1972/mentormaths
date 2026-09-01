<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textbook_chapter_maps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('textbook_id')->constrained()->cascadeOnDelete();
            $table->string('book_chapter_number', 32);
            $table->string('book_chapter_title');
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['textbook_id', 'syllabus_chapter_id']);
            $table->unique(['textbook_id', 'book_chapter_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textbook_chapter_maps');
    }
};
