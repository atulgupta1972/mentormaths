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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class TextbookChapterPublishService
{
    public function __construct(
        private WrittenSheetService $writtenSheetService,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function publish(TextbookChapter $chapter, array $items, User $publisher): TextbookChapter
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
        $gradeCode = $this->gradeCode($syllabusChapter);
        $chapterNum = str_pad((string) $chapter->chapter_number, 2, '0', STR_PAD_LEFT);
        $mcqCode = "{$gradeCode}-TB{$chapterNum}-M";
        $writtenCode = "{$gradeCode}-TB{$chapterNum}-W";

        return DB::transaction(function () use ($chapter, $approved, $publisher, $topic, $syllabusChapter, $mcqCode, $writtenCode) {
            if ($chapter->mcq_worksheet_id) {
                $chapter->mcqWorksheet?->questions()->detach();
                $chapter->mcqWorksheet?->delete();
            }
            if ($chapter->written_worksheet_id) {
                $chapter->writtenWorksheet?->questions()->detach();
                $chapter->writtenWorksheet?->delete();
            }

            $mcqQuestions = [];
            $writtenQuestions = [];
            $sort = 0;

            foreach ($approved as $item) {
                $sort++;

                if ($item['include_in_written'] ?? true) {
                    $writtenQuestions[] = $this->createWrittenQuestion($topic, $item, $publisher->id, $sort);
                }

                if ($item['include_in_mcq'] ?? true) {
                    $mcqQuestions[] = $this->createMcqQuestion($topic, $item, $publisher->id, $sort);
                }
            }

            $mcqWorksheet = null;
            $writtenWorksheet = null;

            if ($mcqQuestions !== []) {
                $mcqWorksheet = Worksheet::create([
                    'title' => "{$chapter->title} — Textbook MCQ",
                    'set_number' => 1,
                    'set_code' => $mcqCode,
                    'tier' => PracticeSetTier::STARTER,
                    'scope' => PracticeSetScope::CHAPTER,
                    'syllabus_chapter_id' => $syllabusChapter->id,
                    'status' => Worksheet::STATUS_PUBLISHED,
                    'delivery_mode' => WorksheetDeliveryMode::ONLINE,
                    'created_by' => $publisher->id,
                ]);

                foreach ($mcqQuestions as $index => $question) {
                    $mcqWorksheet->questions()->attach($question->id, ['sort_order' => $index + 1]);
                }
            }

            if ($writtenQuestions !== []) {
                $writtenWorksheet = Worksheet::create([
                    'title' => "{$chapter->title} — Textbook written",
                    'set_number' => 1,
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
            }

            $chapter->update([
                'extraction_items' => $approved->values()->all(),
                'status' => TextbookChapter::STATUS_PUBLISHED,
                'mcq_worksheet_id' => $mcqWorksheet?->id,
                'written_worksheet_id' => $writtenWorksheet?->id,
                'published_at' => now(),
                'published_by' => $publisher->id,
                'extraction_error' => null,
            ]);

            return $chapter->fresh(['textbook.gradeLevel', 'syllabusChapter', 'mcqWorksheet', 'writtenWorksheet']);
        });
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

    private function gradeCode(SyllabusChapter $chapter): string
    {
        $chapter->loadMissing('syllabusVersion.gradeLevel');
        $name = $chapter->syllabusVersion?->gradeLevel?->name ?? 'C0';
        if (preg_match('/(\d+)/', $name, $matches)) {
            return 'C'.$matches[1];
        }

        return 'C0';
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function createWrittenQuestion(SyllabusTopic $topic, array $item, int $userId, int $sort): Question
    {
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
            'method_hint' => QuestionMethodHint::inferFromQuestionText(trim((string) $item['question_text'])),
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

        return $question;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return \Illuminate\Support\Collection<int, array{text: string, is_correct: bool}>
     */
    private function fallbackMcqOptions(array $item): \Illuminate\Support\Collection
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
