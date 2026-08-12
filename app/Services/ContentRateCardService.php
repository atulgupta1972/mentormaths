<?php

namespace App\Services;

use App\Models\ContentRateCard;
use App\Models\SyllabusChapter;
use App\Models\Textbook;
use App\Models\TextbookChapter;

class ContentRateCardService
{
    /**
     * @return array{amount_inr: int, rate_basis: string}
     */
    public function resolveRateForChapter(TextbookChapter $chapter, string $contentType = ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ): array
    {
        $chapter->loadMissing(['textbook', 'syllabusChapter.syllabusVersion']);

        $gradeLevelId = $chapter->textbook?->grade_level_id;
        $syllabusChapterId = $chapter->syllabus_chapter_id;
        $boardId = $chapter->syllabusChapter?->syllabusVersion?->board_id;

        $card = $this->findBestCard($contentType, $boardId, $gradeLevelId, $syllabusChapterId);

        if (! $card) {
            if ($gradeLevelId) {
                return $this->resolveClassDefaultRate($gradeLevelId, $contentType);
            }

            return [
                'amount_inr' => (int) config('content_operations.default_uploader_rate_inr', 2),
                'rate_basis' => (string) config('content_operations.default_uploader_rate_basis', ContentRateCard::BASIS_PER_QUESTION),
            ];
        }

        return [
            'amount_inr' => (int) $card->default_amount_inr,
            'rate_basis' => $card->rate_basis ?? ContentRateCard::BASIS_PER_SET,
        ];
    }

    public function resolveAmountForChapter(TextbookChapter $chapter, string $contentType = ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ): int
    {
        return $this->resolveRateForChapter($chapter, $contentType)['amount_inr'];
    }

    /**
     * @return array{amount_inr: int, rate_basis: string}
     */
    public function resolveRateForSyllabusChapter(int $gradeLevelId, SyllabusChapter $syllabusChapter, string $contentType = ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ): array
    {
        $syllabusChapter->loadMissing('syllabusVersion');

        $placeholder = new TextbookChapter([
            'syllabus_chapter_id' => $syllabusChapter->id,
        ]);
        $placeholder->setRelation('syllabusChapter', $syllabusChapter);
        $placeholder->setRelation('textbook', new Textbook(['grade_level_id' => $gradeLevelId]));

        return $this->resolveRateForChapter($placeholder, $contentType);
    }

    public function resolveAmountForSyllabusChapter(int $gradeLevelId, SyllabusChapter $syllabusChapter, string $contentType = ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ): int
    {
        return $this->resolveRateForSyllabusChapter($gradeLevelId, $syllabusChapter, $contentType)['amount_inr'];
    }

    /**
     * @return array{amount_inr: int, rate_basis: string}
     */
    public function resolveClassDefaultRate(int $gradeLevelId, string $contentType = ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ): array
    {
        $card = ContentRateCard::query()
            ->where('content_type', $contentType)
            ->where('grade_level_id', $gradeLevelId)
            ->whereNull('syllabus_chapter_id')
            ->orderByDesc('updated_at')
            ->first();

        if (! $card) {
            return [
                'amount_inr' => (int) config('content_operations.default_uploader_rate_inr', 2),
                'rate_basis' => (string) config('content_operations.default_uploader_rate_basis', ContentRateCard::BASIS_PER_QUESTION),
            ];
        }

        return [
            'amount_inr' => (int) $card->default_amount_inr,
            'rate_basis' => $card->rate_basis ?? ContentRateCard::BASIS_PER_SET,
        ];
    }

    public function resolveClassDefaultAmount(int $gradeLevelId, string $contentType = ContentRateCard::TYPE_TEXTBOOK_CHAPTER_MCQ): int
    {
        return $this->resolveClassDefaultRate($gradeLevelId, $contentType)['amount_inr'];
    }

    private function findBestCard(
        string $contentType,
        ?int $boardId,
        ?int $gradeLevelId,
        ?int $syllabusChapterId,
    ): ?ContentRateCard {
        $candidates = ContentRateCard::query()
            ->where('content_type', $contentType)
            ->where(function ($query) use ($boardId, $gradeLevelId, $syllabusChapterId) {
                $query->where(function ($q) use ($boardId, $gradeLevelId, $syllabusChapterId) {
                    $q->where('board_id', $boardId)
                        ->where('grade_level_id', $gradeLevelId)
                        ->where('syllabus_chapter_id', $syllabusChapterId);
                })->orWhere(function ($q) use ($boardId, $gradeLevelId) {
                    $q->where('board_id', $boardId)
                        ->where('grade_level_id', $gradeLevelId)
                        ->whereNull('syllabus_chapter_id');
                })->orWhere(function ($q) use ($gradeLevelId) {
                    $q->whereNull('board_id')
                        ->where('grade_level_id', $gradeLevelId)
                        ->whereNull('syllabus_chapter_id');
                })->orWhere(function ($q) {
                    $q->whereNull('board_id')
                        ->whereNull('grade_level_id')
                        ->whereNull('syllabus_chapter_id');
                });
            })
            ->get();

        $best = null;
        $bestScore = -1;

        foreach ($candidates as $card) {
            $score = 0;
            if ($card->syllabus_chapter_id !== null) {
                $score += 4;
            }
            if ($card->grade_level_id !== null) {
                $score += 2;
            }
            if ($card->board_id !== null) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $card;
            }
        }

        return $best;
    }
}
