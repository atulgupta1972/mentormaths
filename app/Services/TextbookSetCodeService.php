<?php

namespace App\Services;

use App\Models\TextbookChapter;

class TextbookSetCodeService
{
    public const MCQ_BATCH_SIZE = 20;

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

    public function mcqPartCount(int $questionCount): int
    {
        if ($questionCount <= 0) {
            return 0;
        }

        if ($questionCount <= self::MCQ_BATCH_SIZE) {
            return 1;
        }

        return (int) ceil($questionCount / self::MCQ_BATCH_SIZE);
    }

    public function mcqPartCode(TextbookChapter $chapter, int $partNumber, int $totalParts): string
    {
        $base = $this->codes($chapter)['mcq'];

        if ($totalParts <= 1) {
            return $base;
        }

        return "{$base}{$partNumber}";
    }

    /**
     * @return list<array{part: int, count: int, set_code: string, from: int, to: int}>
     */
    public function mcqPartPlan(TextbookChapter $chapter, int $questionCount): array
    {
        if ($questionCount <= 0) {
            return [];
        }

        $totalParts = $this->mcqPartCount($questionCount);
        $plan = [];
        $from = 1;

        for ($part = 1; $part <= $totalParts; $part++) {
            $remaining = $questionCount - ($from - 1);
            $count = min(self::MCQ_BATCH_SIZE, $remaining);
            $plan[] = [
                'part' => $part,
                'count' => $count,
                'set_code' => $this->mcqPartCode($chapter, $part, $totalParts),
                'from' => $from,
                'to' => $from + $count - 1,
            ];
            $from += $count;
        }

        return $plan;
    }

    public function mcqPartPlanSummary(TextbookChapter $chapter, int $questionCount): string
    {
        $plan = $this->mcqPartPlan($chapter, $questionCount);

        if ($plan === []) {
            return $this->codes($chapter)['mcq'];
        }

        if (count($plan) === 1) {
            return $plan[0]['set_code'];
        }

        $counts = implode('+', array_column($plan, 'count'));
        $codes = implode(', ', array_column($plan, 'set_code'));

        return count($plan)." sets ({$counts}): {$codes}";
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
