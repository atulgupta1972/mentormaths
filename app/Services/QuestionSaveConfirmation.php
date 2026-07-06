<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use Illuminate\Support\Collection;

class QuestionSaveConfirmation
{
    public function __construct(private PracticeSetCodeService $codeService) {}

    /**
     * @param  list<Question>|Collection<int, Question>  $saved
     * @return array<string, mixed>
     */
    public function build(
        Collection|array $saved,
        string $bankPurpose,
        ?SyllabusChapter $chapter = null,
        ?SyllabusTopic $topic = null,
    ): array {
        $questions = collect($saved);
        $chapter = $chapter ?? $topic?->chapter;
        $chapter?->loadMissing(['syllabusVersion.board', 'syllabusVersion.gradeLevel']);

        $isPracticeSet = QuestionBankPurpose::isPracticeSet($bankPurpose);

        $banks = $questions
            ->groupBy('syllabus_topic_id')
            ->map(function ($group, $topicId) use ($isPracticeSet) {
                $topicModel = SyllabusTopic::query()->find($topicId);
                if (! $topicModel) {
                    return null;
                }

                $bank = [
                    'topic_name' => $topicModel->name,
                    'questions_count' => $group->count(),
                ];

                if ($isPracticeSet) {
                    $bank['set_code'] = $this->codeService->generate($topicModel, PracticeSetTier::STARTER);
                }

                return $bank;
            })
            ->filter()
            ->values()
            ->all();

        $payload = [
            'bank_purpose' => $bankPurpose,
            'purpose_label' => QuestionBankPurpose::label($bankPurpose),
            'question_count' => $questions->count(),
            'chapter_id' => $chapter?->id,
            'chapter_label' => $chapter
                ? trim(collect([
                    $chapter->syllabusVersion?->board?->code,
                    $chapter->syllabusVersion?->gradeLevel?->name,
                    'Ch '.$chapter->chapter_number.' — '.$chapter->name,
                ])->filter()->implode(' · '))
                : null,
            'banks' => $banks,
        ];

        if ($isPracticeSet && count($banks) === 1) {
            $payload['set_code'] = $banks[0]['set_code'];
        }

        if (! $isPracticeSet && $chapter) {
            $payload['set_code'] = $this->codeService->generateChapterTest($chapter);
            $payload['topics_count'] = count($banks);
        }

        return $payload;
    }
}
