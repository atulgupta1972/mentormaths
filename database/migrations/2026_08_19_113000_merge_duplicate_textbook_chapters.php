<?php

use App\Services\TextbookChapterBookService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(TextbookChapterBookService::class)->mergeAllDuplicateBookChapters();
    }

    public function down(): void
    {
        // Duplicate book+syllabus banks cannot be restored.
    }
};
