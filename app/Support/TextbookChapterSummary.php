<?php

namespace App\Support;

use App\Models\TextbookChapter;

class TextbookChapterSummary
{
    /**
     * @return array<string, mixed>
     */
    public static function forEmail(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'creator']);

        $items = $chapter->extraction_items ?? [];

        return [
            'recipient_name' => $chapter->creator?->name ?? 'Admin',
            'grade_name' => $chapter->textbook?->gradeLevel?->name ?? 'Class',
            'book_name' => $chapter->textbook?->name ?? 'Textbook',
            'chapter_number' => $chapter->chapter_number,
            'chapter_title' => $chapter->title,
            'items_count' => count($items),
            'review_url' => route('admin.textbooks.show', $chapter),
            'index_url' => route('admin.textbooks.index'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function forFailedEmail(TextbookChapter $chapter): array
    {
        $summary = self::forEmail($chapter);
        $summary['error_message'] = $chapter->extraction_error ?: 'Extraction failed.';

        return $summary;
    }
}
