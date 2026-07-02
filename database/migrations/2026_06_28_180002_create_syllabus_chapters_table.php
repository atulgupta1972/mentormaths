<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('syllabus_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('syllabus_version_id')->constrained()->cascadeOnDelete();
            $table->string('chapter_number', 20)->nullable();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('syllabus_chapters');
    }
};
