<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TextbookChapterMap extends Model
{
    protected $fillable = [
        'textbook_id',
        'book_chapter_number',
        'book_chapter_title',
        'syllabus_chapter_id',
        'sort_order',
        'created_by',
    ];

    public function textbook(): BelongsTo
    {
        return $this->belongsTo(Textbook::class);
    }

    public function syllabusChapter(): BelongsTo
    {
        return $this->belongsTo(SyllabusChapter::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookLabel(): string
    {
        $number = trim($this->book_chapter_number);
        $title = trim($this->book_chapter_title);

        if ($number !== '' && $title !== '') {
            return $number.' — '.$title;
        }

        return $title !== '' ? $title : ($number !== '' ? $number : 'Chapter');
    }
}
