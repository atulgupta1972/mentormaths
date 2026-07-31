<?php

namespace App\Services;

use App\Models\TextbookChapter;

class TextbookSetCodeService
{
    /**
     * @return array{mcq: string, written: string}
     */
    public function codes(TextbookChapter $chapter): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.gradeLevel']);

        $gradeCode = $this->gradeCode($chapter);
        $bookCode = $this->bookCode($chapter);
        $chapterNum = str_pad((string) $chapter->chapter_number, 2, '0', STR_PAD_LEFT);

        return [
            'mcq' => "{$gradeCode}-{$bookCode}-CH{$chapterNum}-M",
            'written' => "{$gradeCode}-{$bookCode}-CH{$chapterNum}-W",
        ];
    }

    private function gradeCode(TextbookChapter $chapter): string
    {
        $name = $chapter->textbook?->gradeLevel?->name ?? 'Class 0';
        if (preg_match('/(\d+)/', $name, $matches)) {
            return 'C'.$matches[1];
        }

        return 'C0';
    }

    private function bookCode(TextbookChapter $chapter): string
    {
        $raw = strtoupper(trim((string) ($chapter->textbook?->code ?? 'TB')));
        $clean = preg_replace('/[^A-Z0-9]/', '', $raw) ?? 'TB';

        return substr($clean !== '' ? $clean : 'TB', 0, 8);
    }
}
