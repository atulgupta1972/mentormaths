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
     * Format: S711 = MCQ Starter · Class 7 · Chapter 1 · Set 1.
     * Format: SF711 = Fill-in-blank Starter · Class 7 · Chapter 1 · Set 1.
     */
    public function generate(SyllabusTopic $topic, string $tier, bool $fillInBlank = false): string
    {
        $topic->loadMissing('chapter.syllabusVersion.gradeLevel');

        $chapter = $topic->chapter;

        if (! $chapter || ! $chapter->syllabusVersion?->gradeLevel) {
            throw new \InvalidArgumentException('Topic must belong to a syllabus chapter and class.');
        }

        return $this->allocateChapterTierCode($chapter, $tier, $fillInBlank);
    }

    /**
     * Format: T711 = Chapter test · Class 7 · Chapter 1 · Test 1 (mixed topics).
     */
    public function generateChapterTest(SyllabusChapter $chapter): string
    {
        $chapter->loadMissing('syllabusVersion.gradeLevel');

        if (! $chapter->syllabusVersion?->gradeLevel) {
            throw new \InvalidArgumentException('Chapter must belong to a syllabus version and class.');
        }

        $tier = PracticeSetTier::CHAPTER_TEST;
        $seq = Worksheet::query()
            ->where('scope', PracticeSetScope::CHAPTER)
            ->where('syllabus_chapter_id', $chapter->id)
            ->where('tier', $tier)
            ->count() + 1;

        return $this->ensureUniqueCode($chapter, $tier, $seq, false);
    }

    /**
     * Format: S711 / SF711 = practice set across chapter (MCQ or fill-in-blank).
     */
    public function generateChapterPractice(SyllabusChapter $chapter, string $tier = PracticeSetTier::STARTER, bool $fillInBlank = false): string
    {
        $chapter->loadMissing('syllabusVersion.gradeLevel');

        if (! $chapter->syllabusVersion?->gradeLevel) {
            throw new \InvalidArgumentException('Chapter must belong to a syllabus version and class.');
        }

        return $this->allocateChapterTierCode($chapter, $tier, $fillInBlank);
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

                $fillInBlank = str_contains((string) $worksheet->set_code, 'F')
                    && preg_match('/^[SBC]F\d+/', (string) $worksheet->set_code);
                $prefix = PracticeSetTier::codePrefix($worksheet->tier, (bool) $fillInBlank);
                $classNum = $topic->chapter->syllabusVersion->gradeLevel->sort_order;
                $chapterNum = $this->chapterNumber($topic->chapter);

                $worksheet->update([
                    'set_code' => "{$prefix}{$classNum}{$chapterNum}{$seq}",
                ]);
                $seq++;
            }
        }
    }

    private function allocateChapterTierCode(SyllabusChapter $chapter, string $tier, bool $fillInBlank): string
    {
        $seq = $this->nextSequenceInChapterForTier($chapter, $tier, $fillInBlank);

        return $this->ensureUniqueCode($chapter, $tier, $seq, $fillInBlank);
    }

    private function nextSequenceInChapterForTier(SyllabusChapter $chapter, string $tier, bool $fillInBlank): int
    {
        $codePrefix = $this->codeStem($chapter, $tier, $fillInBlank);

        return Worksheet::query()
            ->where('tier', $tier)
            ->where(function ($query) use ($chapter) {
                $query->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
                    ->orWhere(function ($q) use ($chapter) {
                        $q->where('scope', PracticeSetScope::CHAPTER)
                            ->where('syllabus_chapter_id', $chapter->id);
                    });
            })
            ->where('set_code', 'like', $codePrefix.'%')
            ->count() + 1;
    }

    private function ensureUniqueCode(SyllabusChapter $chapter, string $tier, int $seq, bool $fillInBlank): string
    {
        $code = $this->buildCode($chapter, $tier, $seq, $fillInBlank);

        while (Worksheet::query()->where('set_code', $code)->exists()) {
            $seq++;
            $code = $this->buildCode($chapter, $tier, $seq, $fillInBlank);
        }

        return $code;
    }

    private function buildCode(SyllabusChapter $chapter, string $tier, int $seq, bool $fillInBlank): string
    {
        return $this->codeStem($chapter, $tier, $fillInBlank).$seq;
    }

    private function codeStem(SyllabusChapter $chapter, string $tier, bool $fillInBlank): string
    {
        $chapter->loadMissing('syllabusVersion.gradeLevel');
        $grade = $chapter->syllabusVersion?->gradeLevel;

        if (! $grade) {
            throw new \InvalidArgumentException('Chapter must belong to a syllabus version and class.');
        }

        $prefix = PracticeSetTier::codePrefix($tier, $fillInBlank);
        $classNum = $grade->sort_order;
        $chapterNum = $this->chapterNumber($chapter);

        return "{$prefix}{$classNum}{$chapterNum}";
    }

    private function chapterNumber(SyllabusChapter $chapter): int
    {
        $parsed = (int) preg_replace('/\D/', '', (string) $chapter->chapter_number);

        return $parsed > 0 ? $parsed : (int) $chapter->sort_order;
    }
}
