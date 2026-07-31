<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('textbooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 32);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['grade_level_id', 'code']);
        });

        Schema::create('textbook_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('textbook_id')->constrained()->cascadeOnDelete();
            $table->foreignId('syllabus_chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('chapter_number');
            $table->string('title');
            $table->string('pdf_path');
            $table->string('status', 32)->default('draft');
            $table->json('extraction_items')->nullable();
            $table->text('extraction_error')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->foreignId('mcq_worksheet_id')->nullable()->constrained('worksheets')->nullOnDelete();
            $table->foreignId('written_worksheet_id')->nullable()->constrained('worksheets')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['textbook_id', 'syllabus_chapter_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('textbook_chapters');
        Schema::dropIfExists('textbooks');
    }
};
