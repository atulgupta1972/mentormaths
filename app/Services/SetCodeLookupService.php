<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Support\FillBlankAnswerConsistency;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SetCodeLookupService
{
    public function __construct(
        private PracticeSetCodeService $codeService,
        private ChapterMixedQuestionService $mixedQuestionService,
        private FillBlankAnswerConsistency $answerConsistency,
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
        $fillInBlankFromCode = PracticeSetTier::codeLooksFillInBlank($worksheet->set_code);

        $questions = $this->orderedQuestions($worksheet);
        $restoredFromBank = false;

        if ($questions->isEmpty()) {
            $restoredFromBank = $this->attachUnpackagedQuestions($worksheet, $fillInBlankFromCode);
            $questions = $this->orderedQuestions($worksheet);
        }

        $isFillInBlank = $questions->isNotEmpty()
            ? $questions->every(fn (Question $q) => $q->isFillInBlank())
            : $fillInBlankFromCode;

        $statusLabel = 'Packaged · '.$worksheet->status;
        if ($restoredFromBank) {
            $statusLabel .= ' · questions restored from bank';
        }

        return [
            'set_code' => $worksheet->set_code,
            'status' => $worksheet->status,
            'status_label' => $statusLabel,
            'tier_label' => $worksheet->tier_label,
            'kind_label' => $worksheet->isChapterScope() ? 'Chapter scope' : 'Topic practice',
            'scope_line' => $this->scopeLine($worksheet, $topic, $chapter),
            'class_label' => trim(($board?->code ?? '').' '.($gradeLevel?->name ?? '')),
            'worksheet_id' => $worksheet->id,
            'is_bank' => false,
            'is_fill_in_blank' => $isFillInBlank,
            'is_written' => $worksheet->isWritten() || PracticeSetTier::codeLooksWritten($worksheet->set_code),
            'written_pdf_url' => $worksheet->isWritten() ? $worksheet->writtenPdfUrl() : null,
            'questions_restored' => $restoredFromBank,
            'questions_count' => $questions->count(),
            'questions' => $questions->map(fn (Question $q) => $this->formatQuestion($q))->all(),
            'review_url' => route('admin.questions.sets.show', $worksheet->id),
            'sibling_set' => $this->siblingSetHint($worksheet),
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
        $answerWarning = null;

        if ($question->isFillInBlank()) {
            $answerWarning = $this->answerConsistency->mismatch(
                $question->blankAnswer?->correct_answer,
                $question->explanation,
                $question->blankAnswer?->answer_format,
            );
        }

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
            'diagram_url' => $question->diagram_url,
            'answer_warning' => $answerWarning,
            'options' => $question->options->map(fn ($option, $index) => [
                'letter' => chr(65 + $index),
                'option_text' => $option->option_text,
                'is_correct' => $option->is_correct,
            ])->values()->all(),
        ];
    }

    /**
     * @return Collection<int, Question>
     */
    private function orderedQuestions(Worksheet $worksheet): Collection
    {
        if (! $worksheet->relationLoaded('questions')) {
            $worksheet->load(['questions.options', 'questions.blankAnswer']);
        }

        $questions = $worksheet->questions
            ->sortBy(fn (Question $q) => $q->pivot->sort_order ?? $q->id)
            ->values();

        if ($questions->isNotEmpty()) {
            return $questions;
        }

        $ids = DB::table('worksheet_question')
            ->where('worksheet_id', $worksheet->id)
            ->orderBy('sort_order')
            ->pluck('question_id');

        if ($ids->isEmpty()) {
            return $questions;
        }

        return Question::query()
            ->with(['options', 'blankAnswer'])
            ->whereIn('id', $ids->all())
            ->get()
            ->sortBy(fn (Question $q) => $ids->search($q->id))
            ->values();
    }

    /**
     * Empty published sets often still have unpackaged bank questions that failed to attach.
     * Linking them here restores the lookup table and student attempts.
     */
    private function attachUnpackagedQuestions(Worksheet $worksheet, bool $fillInBlank): bool
    {
        if ($worksheet->isWritten() || $worksheet->isFormula() || $worksheet->isCatchUp()) {
            return false;
        }

        $ids = $this->unpackagedQuestionIdsForWorksheet($worksheet, $fillInBlank);

        if ($ids === []) {
            return false;
        }

        try {
            DB::transaction(function () use ($worksheet, $ids) {
                $alreadyLinked = DB::table('worksheet_question')
                    ->where('worksheet_id', $worksheet->id)
                    ->exists();

                if ($alreadyLinked) {
                    return;
                }

                foreach ($ids as $index => $questionId) {
                    $worksheet->questions()->syncWithoutDetaching([
                        $questionId => ['sort_order' => $index + 1],
                    ]);
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        $worksheet->unsetRelation('questions');
        $worksheet->load(['questions.options', 'questions.blankAnswer']);

        return $worksheet->questions->isNotEmpty();
    }

    /**
     * @return list<int>
     */
    private function unpackagedQuestionIdsForWorksheet(Worksheet $worksheet, bool $fillInBlank): array
    {
        $type = $fillInBlank ? Question::TYPE_FILL_IN_BLANK : Question::TYPE_MCQ;

        if ($worksheet->isChapterScope()) {
            $chapter = $worksheet->chapter;
            if (! $chapter) {
                return [];
            }

            return $this->mixedQuestionService->unpackagedPracticeSetQuestionIdsByType($chapter, $fillInBlank);
        }

        $topicId = $worksheet->syllabus_topic_id;
        if (! $topicId) {
            return [];
        }

        return Question::query()
            ->where('syllabus_topic_id', $topicId)
            ->where('type', $type)
            ->where(fn ($q) => $q
                ->where('bank_purpose', QuestionBankPurpose::PRACTICE_SET)
                ->orWhereNull('bank_purpose'))
            ->whereDoesntHave('worksheets')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private function siblingSetHint(Worksheet $worksheet): ?array
    {
        $siblingCode = PracticeSetTier::siblingFillBlankCode($worksheet->set_code);
        if (! $siblingCode || strcasecmp($siblingCode, (string) $worksheet->set_code) === 0) {
            return null;
        }

        $sibling = Worksheet::query()
            ->whereRaw('UPPER(set_code) = ?', [strtoupper($siblingCode)])
            ->withCount('questions')
            ->first();

        if (! $sibling) {
            return null;
        }

        return [
            'set_code' => $sibling->set_code,
            'questions_count' => (int) $sibling->questions_count,
            'is_fill_in_blank' => PracticeSetTier::codeLooksFillInBlank($sibling->set_code),
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
