<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_heads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('syllabus_chapters', function (Blueprint $table) {
            $table->foreignId('chapter_head_id')
                ->nullable()
                ->after('syllabus_version_id')
                ->constrained('chapter_heads')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('syllabus_chapters', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chapter_head_id');
        });

        Schema::dropIfExists('chapter_heads');
    }
};
