<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;

class SetCodeLookupService
{
    public function __construct(
        private PracticeSetCodeService $codeService,
        private ChapterMixedQuestionService $mixedQuestionService,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function lookup(string $rawCode): ?array
    {
        $code = strtoupper(trim($rawCode));

        if ($code === '') {
            return null;
        }

        $worksheet = Worksheet::query()
            ->whereRaw('UPPER(set_code) = ?', [$code])
            ->with([
                'topic.chapter.syllabusVersion.board',
                'topic.chapter.syllabusVersion.gradeLevel',
                'chapter.syllabusVersion.board',
                'chapter.syllabusVersion.gradeLevel',
                'questions.options',
                'questions.blankAnswer',
            ])
            ->first();

        if ($worksheet) {
            return $this->formatPackaged($worksheet);
        }

        return $this->findUnpackagedBankByCode($code);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPackaged(Worksheet $worksheet): array
    {
        $topic = $worksheet->topic;
        $chapter = $worksheet->chapter ?? $topic?->chapter;
        $gradeLevel = $chapter?->syllabusVersion?->gradeLevel;
        $board = $chapter?->syllabusVersion?->board;

        $questions = $worksheet->questions->sortBy(fn ($q) => $q->pivot->sort_order ?? $q->id)->values();

        return [
            'set_code' => $worksheet->set_code,
            'status' => $worksheet->status,
            'status_label' => 'Packaged · '.$worksheet->status,
            'tier_label' => $worksheet->tier_label,
            'kind_label' => $worksheet->isChapterScope() ? 'Chapter scope' : 'Topic practice',
            'scope_line' => $this->scopeLine($worksheet, $topic, $chapter),
            'class_label' => trim(($board?->code ?? '').' '.($gradeLevel?->name ?? '')),
            'worksheet_id' => $worksheet->id,
            'is_bank' => false,
            'is_fill_in_blank' => $questions->every(fn (Question $q) => $q->isFillInBlank()),
            'questions_count' => $questions->count(),
            'questions' => $questions->map(fn (Question $q) => $this->formatQuestion($q))->all(),
            'review_url' => route('admin.questions.sets.show', $worksheet->id),
        ];
    }

    /**
     * @param  list<int>  $questionIds
     * @return array<string, mixed>
     */
    private function formatBank(
        string $code,
        SyllabusChapter $chapter,
        ?SyllabusTopic $topic,
        array $questionIds,
        bool $fillInBlank,
    ): array {
        $chapter->loadMissing(['syllabusVersion.board', 'syllabusVersion.gradeLevel']);

        $questions = Question::query()
            ->with(['options', 'blankAnswer', 'topic'])
            ->whereIn('id', $questionIds)
            ->orderBy('syllabus_topic_id')
            ->orderBy('id')
            ->get();

        $gradeLevel = $chapter->syllabusVersion?->gradeLevel;
        $board = $chapter->syllabusVersion?->board;

        return [
            'set_code' => $code,
            'status' => 'bank',
            'status_label' => 'Question bank (not packaged yet)',
            'tier_label' => PracticeSetTier::label(PracticeSetTier::STARTER),
            'kind_label' => $topic ? 'Topic practice bank' : 'Chapter practice bank',
            'scope_line' => $topic
                ? "Topic: {$topic->name} (Ch {$chapter->chapter_number} {$chapter->name})"
                : "Chapter: {$chapter->chapter_number} — {$chapter->name}",
            'class_label' => trim(($board?->code ?? '').' '.($gradeLevel?->name ?? '')),
            'worksheet_id' => null,
            'is_bank' => true,
            'is_fill_in_blank' => $fillInBlank,
            'questions_count' => $questions->count(),
            'questions' => $questions->map(fn (Question $q) => $this->formatQuestion($q))->all(),
            'review_url' => route('admin.questions.chapters.show', $chapter->id),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatQuestion(Question $question): array
    {
        $correctOption = $question->options->firstWhere('is_correct', true);

        return [
            'id' => $question->id,
            'question_text' => $question->question_text,
            'type' => $question->type,
            'type_label' => $question->isFillInBlank() ? 'Fill in blank' : 'MCQ',
            'answer_format' => $question->blankAnswer?->answer_format,
            'correct_answer' => $question->blankAnswer?->correct_answer ?? $correctOption?->option_text,
            'decimal_places' => $question->blankAnswer?->decimal_places,
            'explanation' => $question->explanation,
            'method_hint' => $question->method_hint,
            'difficulty' => $question->difficulty,
            'options' => $question->options->map(fn ($option, $index) => [
                'letter' => chr(65 + $index),
                'option_text' => $option->option_text,
                'is_correct' => $option->is_correct,
            ])->values()->all(),
        ];
    }

    private function scopeLine(Worksheet $worksheet, ?SyllabusTopic $topic, ?SyllabusChapter $chapter): ?string
    {
        if ($worksheet->isChapterScope() && $chapter) {
            return "Chapter: {$chapter->chapter_number} — {$chapter->name}";
        }

        if ($topic && $chapter) {
            return "Topic: {$topic->name} (Ch {$chapter->chapter_number} {$chapter->name})";
        }

        return null;
    }

    private function findUnpackagedBankByCode(string $code): ?array
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $activeYear || ! $maths) {
            return null;
        }

        $chapters = SyllabusChapter::query()
            ->whereHas('syllabusVersion', fn ($q) => $q
                ->where('academic_year_id', $activeYear->id)
                ->where('subject_id', $maths->id))
            ->with('topics')
            ->orderBy('sort_order')
            ->get();

        foreach ($chapters as $chapter) {
            foreach ([false, true] as $fillInBlank) {
                $ids = $this->mixedQuestionService->unpackagedPracticeSetQuestionIdsByType($chapter, $fillInBlank);

                if ($ids === []) {
                    continue;
                }

                $predicted = $this->codeService->generateChapterPractice(
                    $chapter,
                    PracticeSetTier::STARTER,
                    $fillInBlank,
                );

                if (strtoupper($predicted) === $code) {
                    return $this->formatBank($code, $chapter, null, $ids, $fillInBlank);
                }
            }

            foreach ($chapter->topics as $topic) {
                foreach ([false, true] as $fillInBlank) {
                    $ids = Question::query()
                        ->where('syllabus_topic_id', $topic->id)
                        ->where('bank_purpose', QuestionBankPurpose::PRACTICE_SET)
                        ->where('type', $fillInBlank ? Question::TYPE_FILL_IN_BLANK : Question::TYPE_MCQ)
                        ->whereDoesntHave('worksheets')
                        ->orderBy('id')
                        ->pluck('id')
                        ->all();

                    if ($ids === []) {
                        continue;
                    }

                    $predicted = $this->codeService->generate($topic, PracticeSetTier::STARTER, $fillInBlank);

                    if (strtoupper($predicted) === $code) {
                        return $this->formatBank($code, $chapter, $topic, $ids, $fillInBlank);
                    }
                }
            }
        }

        return null;
    }
}
