<?php

namespace App\Services;

use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;

class PracticeSetCodeService
{
    /**
     * Format: S711 = Starter · Class 7 · Chapter 1 · Set 1 (within chapter + tier).
     * Next: S712, B711, C711 …
     */
    public function generate(SyllabusTopic $topic, string $tier): string
    {
        $topic->loadMissing('chapter.syllabusVersion.gradeLevel');

        $chapter = $topic->chapter;
        $grade = $chapter?->syllabusVersion?->gradeLevel;

        if (! $chapter || ! $grade) {
            throw new \InvalidArgumentException('Topic must belong to a syllabus chapter and class.');
        }

        $letter = PracticeSetTier::codeLetter($tier);
        $classNum = $grade->sort_order;
        $chapterNum = $this->chapterNumber($chapter);

        $seq = Worksheet::query()
            ->where('tier', $tier)
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->count() + 1;

        return "{$letter}{$classNum}{$chapterNum}{$seq}";
    }

    /**
     * Format: T711 = Chapter test · Class 7 · Chapter 1 · Test 1 (mixed topics).
     */
    public function generateChapterTest(SyllabusChapter $chapter): string
    {
        $chapter->loadMissing('syllabusVersion.gradeLevel');
        $grade = $chapter->syllabusVersion?->gradeLevel;

        if (! $grade) {
            throw new \InvalidArgumentException('Chapter must belong to a syllabus version and class.');
        }

        $classNum = $grade->sort_order;
        $chapterNum = $this->chapterNumber($chapter);

        $seq = Worksheet::query()
            ->where('scope', PracticeSetScope::CHAPTER)
            ->where('syllabus_chapter_id', $chapter->id)
            ->count() + 1;

        $letter = PracticeSetTier::codeLetter(PracticeSetTier::CHAPTER_TEST);

        return "{$letter}{$classNum}{$chapterNum}{$seq}";
    }

    /**
     * Format: S821 = Starter practice · Class 8 · Chapter 2 · Set 1 (mixed topics, guided).
     */
    public function generateChapterPractice(SyllabusChapter $chapter, string $tier = PracticeSetTier::STARTER): string
    {
        $chapter->loadMissing('syllabusVersion.gradeLevel');
        $grade = $chapter->syllabusVersion?->gradeLevel;

        if (! $grade) {
            throw new \InvalidArgumentException('Chapter must belong to a syllabus version and class.');
        }

        $classNum = $grade->sort_order;
        $chapterNum = $this->chapterNumber($chapter);

        $seq = Worksheet::query()
            ->where('scope', PracticeSetScope::CHAPTER)
            ->where('syllabus_chapter_id', $chapter->id)
            ->where('tier', $tier)
            ->count() + 1;

        $letter = PracticeSetTier::codeLetter($tier);

        return "{$letter}{$classNum}{$chapterNum}{$seq}";
    }

    public function backfillAll(): void
    {
        $grouped = Worksheet::query()
            ->with(['topic.chapter.syllabusVersion.gradeLevel'])
            ->orderBy('id')
            ->get()
            ->groupBy(function (Worksheet $worksheet) {
                $chapterId = $worksheet->topic?->syllabus_chapter_id ?? 0;

                return "{$worksheet->tier}-{$chapterId}";
            });

        foreach ($grouped as $sets) {
            $seq = 1;
            foreach ($sets as $worksheet) {
                $topic = $worksheet->topic;
                if (! $topic?->chapter?->syllabusVersion?->gradeLevel) {
                    continue;
                }

                $letter = PracticeSetTier::codeLetter($worksheet->tier);
                $classNum = $topic->chapter->syllabusVersion->gradeLevel->sort_order;
                $chapterNum = $this->chapterNumber($topic->chapter);

                $worksheet->update([
                    'set_code' => "{$letter}{$classNum}{$chapterNum}{$seq}",
                ]);
                $seq++;
            }
        }
    }

    private function chapterNumber(SyllabusChapter $chapter): int
    {
        $parsed = (int) preg_replace('/\D/', '', (string) $chapter->chapter_number);

        return $parsed > 0 ? $parsed : (int) $chapter->sort_order;
    }
}
