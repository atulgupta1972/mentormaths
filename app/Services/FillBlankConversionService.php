<?php

namespace App\Services;

use App\Models\ContentRateCard;
use App\Models\ContentUploadTask;
use App\Models\QuestionBlankAnswer;
use App\Models\TextbookChapter;
use App\Models\User;
use App\Support\AnswerValidationService;
use App\Support\ContentOperationsMailer;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FillBlankConversionService
{
    public function __construct(
        private AnswerValidationService $answers,
        private TextbookChapterPublishService $publishService,
        private ContentRateCardService $rateCards,
    ) {}

    public function assign(
        TextbookChapter $chapter,
        User $uploader,
        User $admin,
        ?int $amountOverrideInr = null,
        ?string $rateBasisOverride = null,
        ?string $adminNotes = null,
    ): ContentUploadTask {
        $items = $chapter->extraction_items ?? [];

        if ($items === [] && $chapter->mcqWorksheetIds() === []) {
            throw new InvalidArgumentException('Publish or import MCQs for this chapter first.');
        }

        $existing = ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('work_type', ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION)
            ->where('status', '!=', ContentUploadTask::STATUS_CANCELLED)
            ->first();

        if ($existing) {
            throw new InvalidArgumentException('Fill-in-blank conversion is already assigned for this chapter.');
        }

        if ($amountOverrideInr !== null) {
            $offered = $amountOverrideInr;
            $rateBasis = $rateBasisOverride ?? ContentRateCard::BASIS_PER_QUESTION;
        } else {
            $resolved = $this->rateCards->resolveRateForChapter($chapter);
            $offered = $resolved['amount_inr'];
            $rateBasis = ContentRateCard::BASIS_PER_QUESTION;
        }

        if ($offered <= 0) {
            throw new InvalidArgumentException('Set a rate (₹ per question) before assigning conversion.');
        }

        $task = ContentUploadTask::query()->create([
            'textbook_chapter_id' => $chapter->id,
            'work_type' => ContentUploadTask::WORK_TYPE_FILL_BLANK_CONVERSION,
            'assigned_to_user_id' => $uploader->id,
            'assigned_by_user_id' => $admin->id,
            'status' => ContentUploadTask::STATUS_PENDING_AGREEMENT,
            'rate_basis' => $rateBasis,
            'offered_amount_inr' => $offered,
            'admin_notes' => $adminNotes,
        ]);

        ContentOperationsMailer::notifyAssigned($uploader, [$task->fresh(['textbookChapter.textbook.gradeLevel'])]);

        return $task->fresh(['assignee', 'textbookChapter.textbook.gradeLevel']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(TextbookChapter $chapter): array
    {
        $items = $chapter->extraction_items ?? [];
        $rows = [];

        foreach ($items as $index => $item) {
            $prefill = $this->prefillFromMcq($item);
            $skipped = (bool) ($item['fill_blank_skipped'] ?? false);
            $stem = trim((string) ($item['fill_blank_question_text'] ?? $prefill['question_text']));
            $answer = trim((string) ($item['fill_blank_correct_answer'] ?? $prefill['correct_answer']));
            $format = (string) ($item['fill_blank_answer_format'] ?? $prefill['answer_format']);
            $places = $item['fill_blank_decimal_places'] ?? $prefill['decimal_places'];
            $checked = filled($item['fill_blank_checked_at'] ?? null)
                && ($item['fill_blank_checked_hash'] ?? null) === $this->checkHash($stem, $format, $answer, $places);

            $rows[] = [
                'index' => $index,
                'source_label' => trim((string) ($item['label'] ?? $item['topic'] ?? '')).' · Q'.($index + 1),
                'mcq_question' => $item['question_text'] ?? '',
                'mcq_answer' => $item['correct_answer'] ?? '',
                'difficulty' => $item['difficulty'] ?? null,
                'fill_blank_question_text' => $stem,
                'fill_blank_correct_answer' => $answer,
                'fill_blank_answer_format' => $format,
                'fill_blank_decimal_places' => $places,
                'skipped' => $skipped,
                'checked' => $checked && ! $skipped,
                'include_in_written' => (bool) ($item['include_in_written'] ?? true),
            ];
        }

        return $rows;
    }

    /**
     * @return array{correct: bool, message: string, checked: bool}
     */
    public function check(ContentUploadTask $task, int $index, string $attempt, array $draft): array
    {
        $this->assertConversionTask($task);
        $this->assertWorkable($task);
        $chapter = $task->textbookChapter;
        $this->writeDraft($chapter, $index, $draft, clearCheck: false);

        $items = $chapter->fresh()->extraction_items ?? [];
        $item = $items[$index] ?? null;

        if (! is_array($item)) {
            throw new InvalidArgumentException('That question is missing.');
        }

        if (! empty($item['fill_blank_skipped'])) {
            throw new InvalidArgumentException('This row is skipped (MCQ only). Unskip to convert it.');
        }

        $stem = trim((string) ($item['fill_blank_question_text'] ?? ''));
        $answer = trim((string) ($item['fill_blank_correct_answer'] ?? ''));
        $format = (string) ($item['fill_blank_answer_format'] ?? QuestionBlankAnswer::FORMAT_TEXT);
        $places = $item['fill_blank_decimal_places'] ?? null;

        if (! str_contains($stem, '____')) {
            throw new InvalidArgumentException('The fill-in-blank question must contain ____.');
        }

        if ($answer === '') {
            throw new InvalidArgumentException('Enter the canonical answer before Check.');
        }

        $attempt = trim($attempt);

        if ($attempt === '') {
            throw new InvalidArgumentException('Type the answer as a student would, then Check.');
        }

        if ($format === QuestionBlankAnswer::FORMAT_INTEGER) {
            $attempt = str_replace(',', '', $attempt);
        }

        $blank = new QuestionBlankAnswer([
            'answer_format' => $format,
            'correct_answer' => $format === QuestionBlankAnswer::FORMAT_INTEGER
                ? str_replace(',', '', $answer)
                : $answer,
            'decimal_places' => is_numeric($places) ? (int) $places : null,
        ]);

        $correct = $this->answers->matchesBlankAnswer($blank, $attempt);

        $items[$index]['fill_blank_checked_at'] = $correct ? now()->toIso8601String() : null;
        $items[$index]['fill_blank_checked_hash'] = $correct
            ? $this->checkHash($stem, $format, $answer, $places)
            : null;
        $items[$index]['include_in_fill_blank'] = $correct;
        // Keep writer's preference; default on. Only checked blanks are published.
        $items[$index]['include_in_written'] = (bool) ($item['include_in_written'] ?? true);

        $chapter->update(['extraction_items' => array_values($items)]);

        return [
            'correct' => $correct,
            'checked' => $correct,
            'expected_answer' => $answer,
            'attempt' => $attempt,
            'message' => $correct
                ? 'Checked — your attempt matches the stored answer.'
                : 'Your attempt did not match. Check the stored answer below — if the MCQ key is wrong, edit the fill-in-blank answer and Check again.',
        ];
    }

    public function saveDraft(ContentUploadTask $task, int $index, array $draft): void
    {
        $this->assertConversionTask($task);
        $this->assertWorkable($task);
        $this->writeDraft($task->textbookChapter, $index, $draft, clearCheck: true);
    }

    public function skip(ContentUploadTask $task, int $index, bool $skipped = true): void
    {
        $this->assertConversionTask($task);
        $this->assertWorkable($task);
        $chapter = $task->textbookChapter;
        $items = $chapter->extraction_items ?? [];

        if (! isset($items[$index])) {
            throw new InvalidArgumentException('That question is missing.');
        }

        $items[$index]['fill_blank_skipped'] = $skipped;
        $items[$index]['include_in_fill_blank'] = ! $skipped && filled($items[$index]['fill_blank_checked_at'] ?? null);
        $items[$index]['fill_blank_checked_at'] = $skipped ? null : ($items[$index]['fill_blank_checked_at'] ?? null);
        $chapter->update(['extraction_items' => array_values($items)]);
    }

    public function submit(ContentUploadTask $task, User $uploader): ContentUploadTask
    {
        $this->assertConversionTask($task);

        if ($task->assigned_to_user_id !== $uploader->id) {
            throw new InvalidArgumentException('You are not assigned to this task.');
        }

        if ($task->status !== ContentUploadTask::STATUS_IN_PROGRESS) {
            throw new InvalidArgumentException('Agree the rate first, then convert and Check every included blank.');
        }

        $rows = $this->rows($task->textbookChapter);
        $included = 0;

        foreach ($rows as $row) {
            if ($row['skipped']) {
                continue;
            }

            $included++;

            if (! $row['checked']) {
                throw new InvalidArgumentException(
                    'Check every included fill-in-blank (student attempt) before submitting. Q'.($row['index'] + 1).' is not checked.',
                );
            }
        }

        if ($included < 1) {
            throw new InvalidArgumentException('Convert and Check at least one blank, or skip the rest as MCQ-only.');
        }

        $task->update([
            'status' => ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
            'submitted_at' => now(),
        ]);

        ContentOperationsMailer::notifySubmittedForPublish($task->fresh([
            'assignee',
            'textbookChapter.textbook.gradeLevel',
        ]));

        return $task->fresh();
    }

    public function publish(ContentUploadTask $task, User $admin): ContentUploadTask
    {
        $this->assertConversionTask($task);

        if ($task->status !== ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH) {
            throw new InvalidArgumentException('Wait until the uploader submits checked blanks.');
        }

        DB::transaction(function () use ($task, $admin) {
            $this->publishService->publishFillBlankAndWritten($task->textbookChapter, $admin);
            $task->update([
                'status' => ContentUploadTask::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $admin->id,
            ]);
        });

        return $task->fresh();
    }

    private function writeDraft(TextbookChapter $chapter, int $index, array $draft, bool $clearCheck): void
    {
        $items = $chapter->extraction_items ?? [];

        if (! isset($items[$index])) {
            throw new InvalidArgumentException('That question is missing.');
        }

        $stem = trim((string) ($draft['fill_blank_question_text'] ?? ''));
        $answer = trim((string) ($draft['fill_blank_correct_answer'] ?? ''));
        $format = (string) ($draft['fill_blank_answer_format'] ?? QuestionBlankAnswer::FORMAT_TEXT);
        $places = $draft['fill_blank_decimal_places'] ?? null;

        if (! in_array($format, QuestionBlankAnswer::formats(), true)) {
            $format = QuestionBlankAnswer::FORMAT_TEXT;
        }

        $items[$index]['fill_blank_question_text'] = $stem;
        $items[$index]['fill_blank_correct_answer'] = $answer;
        $items[$index]['fill_blank_answer_format'] = $format;
        $items[$index]['fill_blank_decimal_places'] = is_numeric($places) ? (int) $places : null;
        $items[$index]['include_in_written'] = (bool) ($draft['include_in_written'] ?? true);

        if ($clearCheck) {
            $hash = $this->checkHash($stem, $format, $answer, $places);
            if (($items[$index]['fill_blank_checked_hash'] ?? null) !== $hash) {
                $items[$index]['fill_blank_checked_at'] = null;
                $items[$index]['fill_blank_checked_hash'] = null;
                $items[$index]['include_in_fill_blank'] = false;
            }
        }

        $chapter->update(['extraction_items' => array_values($items)]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{question_text: string, correct_answer: string, answer_format: string, decimal_places: int|null}
     */
    private function prefillFromMcq(array $item): array
    {
        $mcqAnswer = trim((string) ($item['correct_answer'] ?? ''));
        $stem = trim((string) ($item['question_text'] ?? ''));
        $format = QuestionBlankAnswer::FORMAT_TEXT;
        $places = null;

        if (preg_match('/^-?\d+\/\d+$/', str_replace(' ', '', $mcqAnswer))) {
            $format = QuestionBlankAnswer::FORMAT_FRACTION;
        } elseif (preg_match('/^-?\d+\.\d+$/', str_replace(',', '', $mcqAnswer))) {
            $format = QuestionBlankAnswer::FORMAT_DECIMAL;
            $places = strlen(substr(strrchr(str_replace(',', '', $mcqAnswer), '.') ?: '', 1));
        } elseif (preg_match('/^-?[\d,]+$/', $mcqAnswer) && ! str_contains($mcqAnswer, '+')) {
            $format = QuestionBlankAnswer::FORMAT_INTEGER;
            $mcqAnswer = str_replace(',', '', $mcqAnswer);
        }

        if ($stem !== '' && ! str_contains($stem, '____')) {
            $stem = rtrim($stem, " \t\n\r\0\x0B.?").' The answer is ____.';
        }

        return [
            'question_text' => $stem,
            'correct_answer' => $mcqAnswer,
            'answer_format' => $format,
            'decimal_places' => $places,
        ];
    }

    private function checkHash(string $stem, string $format, string $answer, mixed $places): string
    {
        return hash('sha256', $stem.'|'.$format.'|'.$answer.'|'.(string) $places);
    }

    private function assertConversionTask(ContentUploadTask $task): void
    {
        if (! $task->isFillBlankConversion()) {
            throw new InvalidArgumentException('This task is not a fill-in-blank conversion.');
        }
    }

    private function assertWorkable(ContentUploadTask $task): void
    {
        if ($task->status !== ContentUploadTask::STATUS_IN_PROGRESS) {
            throw new InvalidArgumentException('Agree the rate first, then convert each blank.');
        }
    }
}
