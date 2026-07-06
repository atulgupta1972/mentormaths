<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Support\QuestionBankPurpose;

class ChapterMixedQuestionService
{
    /**
     * @return list<int>
     */
    public function unpackagedQuestionIds(SyllabusChapter $chapter): array
    {
        return Question::query()
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->whereDoesntHave('worksheets')
            ->where(fn ($q) => $q
                ->where('bank_purpose', QuestionBankPurpose::CHAPTER_TEST)
                ->orWhereNull('bank_purpose'))
            ->orderBy('syllabus_topic_id')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     * @return list<int>
     */
    public function unpackagedPracticeSetQuestionIds(SyllabusChapter $chapter): array
    {
        return Question::query()
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->where('bank_purpose', QuestionBankPurpose::PRACTICE_SET)
            ->whereDoesntHave('worksheets')
            ->orderBy('syllabus_topic_id')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    /**
     *
     * @return list<int>
     */
    public function pickMixedQuestionIds(SyllabusChapter $chapter, int $questionsPerTopic = 2, ?int $maxTotal = null): array
    {
        $chapter->load(['topics.questions' => fn ($q) => $q->orderBy('id')]);

        $picked = [];
        $topicPools = $chapter->topics
            ->filter(fn ($topic) => $topic->questions->isNotEmpty())
            ->map(fn ($topic) => $topic->questions->pluck('id')->all())
            ->values();

        if ($topicPools->isEmpty()) {
            return [];
        }

        $round = 0;
        while (true) {
            $addedThisRound = false;

            foreach ($topicPools as $pool) {
                if ($round >= $questionsPerTopic) {
                    continue;
                }

                if (! isset($pool[$round])) {
                    continue;
                }

                $picked[] = $pool[$round];
                $addedThisRound = true;

                if ($maxTotal !== null && count($picked) >= $maxTotal) {
                    return $picked;
                }
            }

            if (! $addedThisRound) {
                break;
            }

            $round++;
        }

        return $picked;
    }

    /**
     * @return Collection<int, array{id: int, question_text: string, topic_name: string, difficulty: ?string}>
     */
    public function questionsForChapter(SyllabusChapter $chapter): Collection
    {
        return Question::query()
            ->with('topic:id,name')
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->orderBy('syllabus_topic_id')
            ->orderBy('id')
            ->get()
            ->map(fn (Question $q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'topic_name' => $q->topic?->name,
                'difficulty' => $q->difficulty,
            ]);
    }
}
