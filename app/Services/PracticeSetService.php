<?php

namespace App\Services;

use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;

class PracticeSetService
{
    public function __construct(private PracticeSetCodeService $codeService) {}

    public function nextSetNumber(int $topicId): int
    {
        $max = Worksheet::query()
            ->where('scope', PracticeSetScope::TOPIC)
            ->where('syllabus_topic_id', $topicId)
            ->max('set_number');

        return ($max ?? 0) + 1;
    }

    public function nextChapterSetNumber(int $chapterId): int
    {
        $max = Worksheet::query()
            ->where('scope', PracticeSetScope::CHAPTER)
            ->where('syllabus_chapter_id', $chapterId)
            ->max('set_number');

        return ($max ?? 0) + 1;
    }

    public function generateTitle(int $setNumber, string $tier, int $questionCount): string
    {
        $label = PracticeSetTier::label($tier);

        return "Set {$setNumber} — {$label} ({$questionCount} sums)";
    }

    public function prepareForCreate(SyllabusTopic $topic, string $tier, int $questionCount): array
    {
        $setNumber = $this->nextSetNumber($topic->id);
        $setCode = $this->codeService->generate($topic, $tier);

        return [
            'set_number' => $setNumber,
            'set_code' => $setCode,
            'title' => "{$setCode} — ".$this->generateTitle($setNumber, $tier, $questionCount),
        ];
    }

    public function prepareChapterTestCreate(SyllabusChapter $chapter, int $questionCount): array
    {
        $setNumber = $this->nextChapterSetNumber($chapter->id);
        $setCode = $this->codeService->generateChapterTest($chapter);
        $tier = PracticeSetTier::CHAPTER_TEST;

        return [
            'set_number' => $setNumber,
            'set_code' => $setCode,
            'title' => "{$setCode} — Chapter test {$setNumber} ({$questionCount} sums)",
            'tier' => $tier,
        ];
    }

    public function createChapterTest(
        SyllabusChapter $chapter,
        array $questionIds,
        int $userId,
        string $status = Worksheet::STATUS_DRAFT,
        ?string $notes = null,
    ): Worksheet {
        $meta = $this->prepareChapterTestCreate($chapter, count($questionIds));

        $practiceSet = Worksheet::create([
            'title' => $meta['title'],
            'set_number' => $meta['set_number'],
            'set_code' => $meta['set_code'],
            'tier' => $meta['tier'],
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $chapter->id,
            'syllabus_topic_id' => null,
            'notes' => $notes,
            'status' => $status,
            'created_by' => $userId,
        ]);

        foreach ($questionIds as $index => $questionId) {
            $practiceSet->questions()->attach($questionId, ['sort_order' => $index + 1]);
        }

        return $practiceSet->loadCount('questions');
    }
}
