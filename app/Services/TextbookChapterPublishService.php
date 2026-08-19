<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionOption;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Models\Worksheet;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\QuestionMethodHint;
use App\Support\WorksheetDeliveryMode;
use App\Support\WrittenSheetStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class TextbookChapterPublishService
{
    public function __construct(
        private WrittenSheetService $writtenSheetService,
        private TextbookSetCodeService $setCodeService,
        private TextbookMcqSetPlanService $setPlanService,
        private QuestionDiagramService $diagramService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>|null  $setPlan
     */
    public function publish(TextbookChapter $chapter, array $items, User $publisher, ?array $setPlan = null): TextbookChapter
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.gradeLevel']);

        $approved = collect($items)
            ->filter(fn (array $item) => ($item['approved'] ?? true) && trim((string) ($item['question_text'] ?? '')) !== '')
            ->values();

        if ($approved->isEmpty()) {
            throw new InvalidArgumentException('Approve at least one question before publishing.');
        }

        $syllabusChapter = $chapter->syllabusChapter;
        if (! $syllabusChapter) {
            throw new InvalidArgumentException('Syllabus chapter is missing.');
        }

        $topic = $this->textbookTopic($syllabusChapter);
        $writtenCode = $this->setCodeService->codes($chapter)['written'];
        $questionCount = count($items);
        $resolvedPlan = $this->setPlanService->normalizePlanRows(
            $setPlan ?? $chapter->mcq_set_plan ?? [],
            $chapter,
            $questionCount,
        );
        $hasDerivedFillBlank = collect($items)->contains(
            fn (array $item) => filled($item['fill_blank_question_text'] ?? null),
        );

        return DB::transaction(function () use ($chapter, $items, $publisher, $topic, $syllabusChapter, $writtenCode, $resolvedPlan, $hasDerivedFillBlank) {
            $this->deleteExistingMcqWorksheets($chapter);

            if (! $hasDerivedFillBlank && $chapter->written_worksheet_id) {
                $chapter->writtenWorksheet?->questions()->detach();
                $chapter->writtenWorksheet?->delete();
            }

            $writtenQuestions = [];
            $mcqQuestionsByPosition = [];
            $approvedItems = [];

            foreach ($items as $index => $item) {
                $position = $index + 1;
                $isApproved = ($item['approved'] ?? true) && trim((string) ($item['question_text'] ?? '')) !== '';

                if (! $isApproved) {
                    continue;
                }

                $approvedItems[] = $item;

                if (($item['include_in_written'] ?? true) && ! filled($item['fill_blank_question_text'] ?? null)) {
                    $writtenQuestions[] = $this->createWrittenQuestion($topic, $item, $publisher->id, $position);
                }

                if ($item['include_in_mcq'] ?? true) {
                    $mcqQuestionsByPosition[$position] = $this->createMcqQuestion($topic, $item, $publisher->id, $position);
                }
            }

            if ($approvedItems === []) {
                throw new InvalidArgumentException('Approve at least one question before publishing.');
            }

            $mcqWorksheetIds = $this->createMcqWorksheets($chapter, $syllabusChapter, $publisher, $mcqQuestionsByPosition, $resolvedPlan);
            $writtenWorksheet = null;

            if (! $hasDerivedFillBlank) {
                $writtenWorksheet = $this->createWrittenWorksheet(
                    $chapter,
                    $syllabusChapter,
                    $publisher,
                    $writtenQuestions,
                    $writtenCode,
                );
            }

            $chapter->update([
                'extraction_items' => $items,
                'mcq_set_plan' => $resolvedPlan,
                'status' => TextbookChapter::STATUS_PUBLISHED,
                'mcq_worksheet_id' => $mcqWorksheetIds[0] ?? null,
                'mcq_worksheet_ids' => $mcqWorksheetIds !== [] ? $mcqWorksheetIds : null,
                'written_worksheet_id' => $hasDerivedFillBlank
                    ? $chapter->written_worksheet_id
                    : ($writtenWorksheet?->id),
                'published_at' => now(),
                'published_by' => $publisher->id,
                'extraction_error' => null,
            ]);

            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet', 'fillBlankWorksheet']);
        });
    }

    /**
     * Publish newly appended MCQs onto the existing last worksheet without wiping assigned sets.
     *
     * @param  list<array<string, mixed>>  $newItems
     * @return array{added_count: int}
     */
    public function appendPublishedMcqs(TextbookChapter $chapter, array $newItems, User $publisher): array
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.gradeLevel']);

        $syllabusChapter = $chapter->syllabusChapter;
        if (! $syllabusChapter) {
            throw new InvalidArgumentException('Syllabus chapter is missing.');
        }

        $worksheetIds = $chapter->mcqWorksheetIds();
        if ($worksheetIds === []) {
            return ['added_count' => 0];
        }

        $approved = collect($newItems)
            ->filter(fn (array $item) => ($item['approved'] ?? true) && trim((string) ($item['question_text'] ?? '')) !== '')
            ->values();

        if ($approved->isEmpty()) {
            return ['added_count' => 0];
        }

        $topic = $this->textbookTopic($syllabusChapter);
        $lastWorksheet = Worksheet::query()->find($worksheetIds[array_key_last($worksheetIds)]);
        if (! $lastWorksheet) {
            throw new InvalidArgumentException('Published MCQ worksheet is missing.');
        }

        return DB::transaction(function () use ($chapter, $approved, $publisher, $topic, $lastWorksheet) {
            $nextSort = (int) $lastWorksheet->questions()->max('worksheet_question.sort_order');
            $created = 0;

            foreach ($approved as $item) {
                if (! ($item['include_in_mcq'] ?? true)) {
                    continue;
                }

                $question = $this->createMcqQuestion($topic, $item, $publisher->id, $nextSort + $created + 1);
                $lastWorksheet->questions()->attach($question->id, ['sort_order' => $nextSort + $created + 1]);
                $created++;
            }

            $plan = $chapter->mcq_set_plan ?? [];
            if ($plan !== []) {
                $last = count($plan) - 1;
                $plan[$last]['q_to'] = count($chapter->extraction_items ?? []);
                $chapter->update(['mcq_set_plan' => $plan]);
            }

            return ['added_count' => $created];
        });
    }

    public function removePublishedMcqAtIndex(TextbookChapter $chapter, int $itemIndex): void
    {
        $question = $this->publishedQuestionForItemIndex($chapter, $itemIndex);
        if (! $question) {
            return;
        }

        $question->worksheets()->detach();

        $hasAttempts = DB::table('set_attempt_answers')->where('question_id', $question->id)->exists();
        if (! $hasAttempts) {
            $question->options()->delete();
            $question->blankAnswer()->delete();
            $question->delete();
        }
    }

    public function publishedQuestionForItemIndex(TextbookChapter $chapter, int $itemIndex): ?Question
    {
        $position = $itemIndex + 1;
        $setPlan = $chapter->mcq_set_plan ?? [];
        $worksheetIds = $chapter->mcqWorksheetIds();

        if ($setPlan === [] || $worksheetIds === []) {
            return null;
        }

        $worksheets = Worksheet::query()
            ->whereIn('id', $worksheetIds)
            ->orderBy('set_number')
            ->get()
            ->values();

        foreach ($setPlan as $planIndex => $row) {
            $qFrom = (int) ($row['q_from'] ?? 0);
            $qTo = (int) ($row['q_to'] ?? 0);

            if ($position < $qFrom || $position > $qTo) {
                continue;
            }

            $worksheet = $worksheets[$planIndex] ?? null;
            if (! $worksheet) {
                return null;
            }

            $questions = $worksheet->questions()->orderByPivot('sort_order')->get();

            return $questions[$position - $qFrom] ?? null;
        }

        return null;
    }

    public function publishFillBlankAndWritten(TextbookChapter $chapter, User $publisher): TextbookChapter
    {
        $chapter->loadMissing(['textbook.gradeLevel', 'syllabusChapter.syllabusVersion.gradeLevel']);

        $items = $chapter->extraction_items ?? [];
        $fillBlankItems = collect($items)
            ->filter(fn (array $item) => filled($item['fill_blank_question_text'] ?? null)
                && filled($item['fill_blank_correct_answer'] ?? null))
            ->values();

        if ($fillBlankItems->isEmpty()) {
            throw new InvalidArgumentException('Import fill-in-blank JSON first (Step 4).');
        }

        $syllabusChapter = $chapter->syllabusChapter;
        if (! $syllabusChapter) {
            throw new InvalidArgumentException('Syllabus chapter is missing.');
        }

        $topic = $this->textbookTopic($syllabusChapter);
        $mcqCount = count($items);
        $fillPlan = $this->setCodeService->fillBlankPartPlan($chapter, $mcqCount);
        $writtenPlan = $this->setCodeService->writtenPartPlan($chapter, $mcqCount);

        return DB::transaction(function () use ($chapter, $items, $publisher, $topic, $syllabusChapter, $fillPlan, $writtenPlan) {
            $this->deleteExistingWorksheets($chapter->fillBlankWorksheetIds());
            $this->deleteExistingWorksheets($chapter->writtenWorksheetIds());

            $fillBlankByIndex = [];
            $writtenByIndex = [];

            foreach ($items as $index => $item) {
                if (! filled($item['fill_blank_question_text'] ?? null)
                    || ! filled($item['fill_blank_correct_answer'] ?? null)) {
                    continue;
                }

                $fields = $this->fillBlankFields($item);
                $sourceIndex = $index + 1;
                $fillBlankByIndex[$sourceIndex] = $this->createFillBlankQuestion($topic, $item, $fields, $publisher->id);

                if ($item['include_in_written'] ?? true) {
                    $writtenByIndex[$sourceIndex] = $this->createFillBlankQuestion($topic, $item, $fields, $publisher->id);
                }
            }

            $fillBlankIds = $this->createFillBlankWorksheets(
                $chapter,
                $syllabusChapter,
                $publisher,
                $fillBlankByIndex,
                $fillPlan,
            );

            $writtenIds = $this->createWrittenWorksheets(
                $chapter,
                $syllabusChapter,
                $publisher,
                $writtenByIndex,
                $writtenPlan,
            );

            if ($fillBlankIds === []) {
                throw new InvalidArgumentException('No convertible fill-in-blank questions to publish.');
            }

            $chapter->update([
                'fill_blank_worksheet_id' => $fillBlankIds[0] ?? null,
                'fill_blank_worksheet_ids' => $fillBlankIds === [] ? null : $fillBlankIds,
                'written_worksheet_id' => $writtenIds[0] ?? null,
                'written_worksheet_ids' => $writtenIds === [] ? null : $writtenIds,
            ]);

            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet', 'fillBlankWorksheet']);
        });
    }

    private function deleteExistingMcqWorksheets(TextbookChapter $chapter): void
    {
        $this->deleteExistingWorksheets($chapter->mcqWorksheetIds());
    }

    /**
     * @param  list<int>  $worksheetIds
     */
    private function deleteExistingWorksheets(array $worksheetIds): void
    {
        foreach ($worksheetIds as $worksheetId) {
            $worksheet = Worksheet::query()->find($worksheetId);
            $worksheet?->questions()->detach();
            $worksheet?->delete();
        }
    }

    /**
     * @param  array<int, Question>  $mcqQuestionsByPosition
     * @param  list<array{set_code: string, q_from: int, q_to: int, description: string}>  $setPlan
     * @return list<int>
     */
    private function createMcqWorksheets(
        TextbookChapter $chapter,
        SyllabusChapter $syllabusChapter,
        User $publisher,
        array $mcqQuestionsByPosition,
        array $setPlan,
    ): array {
        if ($mcqQuestionsByPosition === []) {
            return [];
        }

        $worksheetIds = [];

        foreach ($setPlan as $index => $row) {
            $chunk = [];

            for ($position = $row['q_from']; $position <= $row['q_to']; $position++) {
                if (isset($mcqQuestionsByPosition[$position])) {
                    $chunk[] = $mcqQuestionsByPosition[$position];
                }
            }

            if ($chunk === []) {
                throw new InvalidArgumentException("Set {$row['set_code']} has no approved MCQs in Q{$row['q_from']}–{$row['q_to']}.");
            }

            $description = trim((string) ($row['description'] ?? ''));
            $titleSuffix = $description !== '' ? " — {$description}" : (count($setPlan) > 1 ? ' — Part '.($index + 1) : '');

            $worksheet = Worksheet::create([
                'title' => "{$chapter->title} — Textbook MCQ{$titleSuffix}",
                'set_number' => $index + 1,
                'set_code' => $row['set_code'],
                'tier' => PracticeSetTier::STARTER,
                'scope' => PracticeSetScope::CHAPTER,
                'syllabus_chapter_id' => $syllabusChapter->id,
                'status' => Worksheet::STATUS_PUBLISHED,
                'delivery_mode' => WorksheetDeliveryMode::ONLINE,
                'created_by' => $publisher->id,
            ]);

            foreach ($chunk as $questionIndex => $question) {
                $worksheet->questions()->attach($question->id, ['sort_order' => $questionIndex + 1]);
            }

            $worksheetIds[] = $worksheet->id;
        }

        return $worksheetIds;
    }

    /**
     * @param  array<int, Question>  $questionsByIndex
     * @param  list<array{part: int, count: int, set_code: string, from: int, to: int}>  $plan
     * @return list<int>
     */
    private function createFillBlankWorksheets(
        TextbookChapter $chapter,
        SyllabusChapter $syllabusChapter,
        User $publisher,
        array $questionsByIndex,
        array $plan,
    ): array {
        $worksheetIds = [];

        foreach ($plan as $row) {
            $chunk = [];

            for ($sourceIndex = $row['from']; $sourceIndex <= $row['to']; $sourceIndex++) {
                if (isset($questionsByIndex[$sourceIndex])) {
                    $chunk[] = $questionsByIndex[$sourceIndex];
                }
            }

            if ($chunk === []) {
                continue;
            }

            $worksheetIds[] = $this->createFillBlankWorksheet(
                $chapter,
                $syllabusChapter,
                $publisher,
                $chunk,
                $row['set_code'],
                (int) $row['part'],
            )->id;
        }

        return $worksheetIds;
    }

    /**
     * @param  array<int, Question>  $questionsByIndex
     * @param  list<array{part: int, count: int, set_code: string, from: int, to: int}>  $plan
     * @return list<int>
     */
    private function createWrittenWorksheets(
        TextbookChapter $chapter,
        SyllabusChapter $syllabusChapter,
        User $publisher,
        array $questionsByIndex,
        array $plan,
    ): array {
        $worksheetIds = [];

        foreach ($plan as $row) {
            $chunk = [];

            for ($sourceIndex = $row['from']; $sourceIndex <= $row['to']; $sourceIndex++) {
                if (isset($questionsByIndex[$sourceIndex])) {
                    $chunk[] = $questionsByIndex[$sourceIndex];
                }
            }

            if ($chunk === []) {
                continue;
            }

            $created = $this->createWrittenWorksheet(
                $chapter,
                $syllabusChapter,
                $publisher,
                $chunk,
                $row['set_code'],
                (int) $row['part'],
            );

            if ($created) {
                $worksheetIds[] = $created->id;
            }
        }

        return $worksheetIds;
    }

    /**
     * @param  list<Question>  $writtenQuestions
     */
    private function createWrittenWorksheet(
        TextbookChapter $chapter,
        SyllabusChapter $syllabusChapter,
        User $publisher,
        array $writtenQuestions,
        string $writtenCode,
        int $setNumber = 1,
    ): ?Worksheet {
        if ($writtenQuestions === []) {
            return null;
        }

        $writtenWorksheet = Worksheet::create([
            'title' => "{$chapter->title} — Textbook written".($setNumber > 1 ? " — Part {$setNumber}" : ''),
            'set_number' => $setNumber,
            'set_code' => $writtenCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::WRITTEN,
            'written_status' => WrittenSheetStatus::VERIFIED,
            'written_pdf_path' => $chapter->pdf_path,
            'written_verified_at' => now(),
            'written_verified_by' => $publisher->id,
            'created_by' => $publisher->id,
        ]);

        foreach ($writtenQuestions as $index => $question) {
            $writtenWorksheet->questions()->attach($question->id, ['sort_order' => $index + 1]);
        }

        $this->writtenSheetService->generatePdf($writtenWorksheet->fresh(['questions.blankAnswer', 'questions.options']));

        return $writtenWorksheet;
    }

    private function textbookTopic(SyllabusChapter $chapter): SyllabusTopic
    {
        return SyllabusTopic::query()->firstOrCreate(
            [
                'syllabus_chapter_id' => $chapter->id,
                'name' => 'Textbook',
            ],
            [
                'sort_order' => 900,
                'learning_outcomes' => 'Textbook examples and exercises',
            ],
        );
    }

    /**
     * @param  list<Question>  $questions
     */
    private function createFillBlankWorksheet(
        TextbookChapter $chapter,
        SyllabusChapter $syllabusChapter,
        User $publisher,
        array $questions,
        string $fillBlankCode,
        int $setNumber = 1,
    ): Worksheet {
        if ($questions === []) {
            throw new InvalidArgumentException('No fill-in-blank questions to publish.');
        }

        $worksheet = Worksheet::create([
            'title' => "{$chapter->title} — Textbook fill in blank".($setNumber > 1 ? " — Part {$setNumber}" : ''),
            'set_number' => $setNumber,
            'set_code' => $fillBlankCode,
            'tier' => PracticeSetTier::STARTER,
            'scope' => PracticeSetScope::CHAPTER,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'delivery_mode' => WorksheetDeliveryMode::ONLINE,
            'created_by' => $publisher->id,
        ]);

        foreach ($questions as $index => $question) {
            $worksheet->questions()->attach($question->id, ['sort_order' => $index + 1]);
        }

        return $worksheet;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{question_text: string, correct_answer: string, answer_format: string, decimal_places: mixed, explanation: mixed, method_hint: mixed}
     */
    private function fillBlankFields(array $item): array
    {
        return [
            'question_text' => trim((string) ($item['fill_blank_question_text'] ?? $item['question_text'] ?? '')),
            'correct_answer' => trim((string) ($item['fill_blank_correct_answer'] ?? $item['correct_answer'] ?? '')),
            'answer_format' => (string) ($item['fill_blank_answer_format'] ?? $item['answer_format'] ?? 'text'),
            'decimal_places' => $item['fill_blank_decimal_places'] ?? null,
            'explanation' => $item['fill_blank_explanation'] ?? $item['explanation'] ?? null,
            'method_hint' => $item['fill_blank_method_hint'] ?? $item['method_hint'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{question_text: string, correct_answer: string, answer_format: string, decimal_places: mixed, explanation: mixed, method_hint: mixed}  $fields
     */
    private function createFillBlankQuestion(SyllabusTopic $topic, array $item, array $fields, int $userId): Question
    {
        $question = Question::create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => $fields['question_text'],
            'explanation' => QuestionMethodHint::sanitizeExplanation($fields['explanation'] ?? null),
            'method_hint' => filled($fields['method_hint'] ?? null)
                ? trim((string) $fields['method_hint'])
                : QuestionMethodHint::inferFromQuestionText($fields['question_text']),
            'source' => Question::SOURCE_PDF,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
            'created_by' => $userId,
        ]);

        QuestionBlankAnswer::create([
            'question_id' => $question->id,
            'answer_format' => $this->normalizeAnswerFormat((string) $fields['answer_format']),
            'correct_answer' => $fields['correct_answer'],
            'decimal_places' => $fields['decimal_places'] ?? null,
        ]);

        $this->attachStagingDiagram($question, $item);

        return $question;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createWrittenQuestion(SyllabusTopic $topic, array $item, int $userId, int $sort): Question
    {
        if (filled($item['fill_blank_question_text'] ?? null)) {
            return $this->createFillBlankQuestion($topic, $item, $this->fillBlankFields($item), $userId);
        }

        $question = Question::create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_FILL_IN_BLANK,
            'question_text' => trim((string) $item['question_text']),
            'explanation' => QuestionMethodHint::sanitizeExplanation($item['explanation'] ?? null),
            'method_hint' => QuestionMethodHint::inferFromQuestionText(trim((string) $item['question_text'])),
            'source' => Question::SOURCE_PDF,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
            'created_by' => $userId,
        ]);

        QuestionBlankAnswer::create([
            'question_id' => $question->id,
            'answer_format' => $this->normalizeAnswerFormat((string) ($item['answer_format'] ?? 'text')),
            'correct_answer' => trim((string) ($item['correct_answer'] ?? '')),
        ]);

        $this->attachStagingDiagram($question, $item);

        return $question;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createMcqQuestion(SyllabusTopic $topic, array $item, int $userId, int $sort): Question
    {
        $question = Question::create([
            'syllabus_topic_id' => $topic->id,
            'type' => Question::TYPE_MCQ,
            'question_text' => trim((string) $item['question_text']),
            'explanation' => QuestionMethodHint::sanitizeExplanation($item['explanation'] ?? null),
            'method_hint' => filled($item['method_hint'] ?? null)
                ? trim((string) $item['method_hint'])
                : QuestionMethodHint::inferFromQuestionText(trim((string) $item['question_text'])),
            'source' => Question::SOURCE_PDF,
            'bank_purpose' => QuestionBankPurpose::PRACTICE_SET,
            'created_by' => $userId,
        ]);

        $options = collect($item['mcq_options'] ?? [])->take(4)->values();
        if ($options->isEmpty()) {
            $options = $this->fallbackMcqOptions($item);
        }

        $hasCorrect = $options->contains(fn ($opt) => (bool) ($opt['is_correct'] ?? false));
        if (! $hasCorrect && filled($item['correct_answer'] ?? null)) {
            $options = $options->map(function ($opt, $index) use ($item) {
                $opt['is_correct'] = $index === 0;
                if ($index === 0) {
                    $opt['text'] = (string) $item['correct_answer'];
                }

                return $opt;
            });
        }

        foreach ($options as $index => $option) {
            QuestionOption::create([
                'question_id' => $question->id,
                'option_text' => trim((string) ($option['text'] ?? '')),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'sort_order' => $index + 1,
            ]);
        }

        $this->attachStagingDiagram($question, $item);

        return $question;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function attachStagingDiagram(Question $question, array $item): void
    {
        $path = trim((string) ($item['diagram_staging_path'] ?? ''));
        if ($path === '' || ! Storage::disk('public')->exists($path)) {
            return;
        }

        $this->diagramService->attachFromPath($question, Storage::disk('public')->path($path));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return Collection<int, array{text: string, is_correct: bool}>
     */
    private function fallbackMcqOptions(array $item): Collection
    {
        $correct = trim((string) ($item['correct_answer'] ?? '—'));

        return collect([
            ['text' => $correct, 'is_correct' => true],
            ['text' => 'None of these', 'is_correct' => false],
            ['text' => 'Cannot be determined', 'is_correct' => false],
            ['text' => '0', 'is_correct' => false],
        ]);
    }

    private function normalizeAnswerFormat(string $format): string
    {
        return in_array($format, ['integer', 'decimal', 'fraction'], true) ? $format : 'text';
    }
}
