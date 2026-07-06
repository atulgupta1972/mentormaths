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
        $topicsCount = $questions->pluck('syllabus_topic_id')->unique()->count();

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
            'topics_count' => $topicsCount,
        ];

        if ($isPracticeSet && $chapter) {
            $payload['set_code'] = $this->codeService->generateChapterPractice($chapter);
            $payload['mode_label'] = 'Guided practice — one question at a time';
        } elseif (! $isPracticeSet && $chapter) {
            $payload['set_code'] = $this->codeService->generateChapterTest($chapter);
            $payload['mode_label'] = 'Chapter test — all questions together';
        } elseif ($isPracticeSet && $topic) {
            $payload['set_code'] = $this->codeService->generate($topic, PracticeSetTier::STARTER);
            $payload['mode_label'] = 'Guided practice — one question at a time';
        }

        return $payload;
    }
}
