<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\WrittenSubmission;
use App\Models\WrittenSubmissionItem;
use App\Support\WrittenSubmissionMailer;
use App\Support\WrittenSubmissionProgress;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WrittenGradingService
{
    private const MAX_GRADING_PAGES = 10;

    public function __construct(
        private WrittenSheetPdfService $pdfService,
        private PdfPageImageService $pageImageService,
        private WrittenUploadOptimizer $uploadOptimizer,
        private PracticeCorrectionQueueService $correctionQueue,
    ) {}

    public function grade(WrittenSubmission $submission): WrittenSubmission
    {
        $submission->load([
            'assignment.practiceSet.questions.options',
            'assignment.practiceSet.questions.blankAnswer',
        ]);

        $assignment = $submission->assignment;
        $worksheet = $assignment->practiceSet;
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY is not configured on the server.');
        }

        $submission->update(['status' => WrittenSubmission::STATUS_PROCESSING]);
        WrittenSubmissionProgress::update($submission, 15, 'Preparing');

        $questions = $this->questionPayload($worksheet->questions->values()->all());

        WrittenSubmissionProgress::update($submission, 35, 'Reading answer sheet');
        $prepared = $this->prepareGradingImages($submission);

        if ($prepared['parts'] === []) {
            throw new \RuntimeException('Uploaded files could not be read.');
        }

        $prompt = $this->buildPrompt($questions, $worksheet->set_code, count($prepared['pages']));

        WrittenSubmissionProgress::update($submission, 55, 'Checking with AI');
        $payload = $this->callGradingModel($apiKey, $prompt, $prepared['parts']);

        WrittenSubmissionProgress::update($submission, 85, 'Saving marks');

        return $this->persistResults(
            $submission,
            $assignment,
            $worksheet->questions->values()->all(),
            $payload,
            $prepared['pages'],
            sendEmail: true,
        );
    }

    /**
     * Re-read one question from the uploaded pages and update only that item.
     */
    public function gradeQuestion(WrittenSubmission $submission, int $questionNumber): WrittenSubmissionItem
    {
        $submission->load([
            'items',
            'assignment.practiceSet.questions.options',
            'assignment.practiceSet.questions.blankAnswer',
        ]);

        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY is not configured on the server.');
        }

        $questions = $submission->assignment->practiceSet->questions->values();
        $question = $questions[$questionNumber - 1] ?? null;

        if (! $question) {
            throw new \InvalidArgumentException("Question {$questionNumber} was not found on this sheet.");
        }

        $item = $submission->items->firstWhere('question_number', $questionNumber);

        if (! $item) {
            throw new \InvalidArgumentException("No graded row found for Q{$questionNumber}.");
        }

        $prepared = $this->prepareGradingImages($submission, keepExistingPages: true);

        if ($prepared['parts'] === []) {
            throw new \RuntimeException('Uploaded files could not be read.');
        }

        $preferredPage = (int) ($item->source_page ?? 0);
        // Always send every page for a re-read so a wrong stored page cannot hide the answer.
        $parts = $prepared['parts'];
        $pages = $prepared['pages'];

        $questionPayload = $this->questionPayload([$question], $questionNumber);
        $prompt = $this->buildSingleQuestionPrompt($questionPayload[0], $submission->assignment->practiceSet->set_code, count($parts));
        $payload = $this->callGradingModel($apiKey, $prompt, $parts);

        $row = collect($payload['items'] ?? [])->firstWhere('question_number', $questionNumber)
            ?? collect($payload['items'] ?? [])->first()
            ?? [];

        if ($row === [] && isset($payload['extracted_answer'])) {
            $row = $payload;
        }

        $score = max(0, min(1, (int) ($row['score'] ?? 0)));
        $sourcePage = $this->resolveSourcePage($row, $pages, $preferredPage > 0 ? $preferredPage : null, $questionNumber, 1);
        $sourceImagePath = $sourcePage ? ($pages[$sourcePage - 1]['path'] ?? null) : ($pages[0]['path'] ?? null);

        $item->update([
            'extracted_answer' => isset($row['extracted_answer']) ? (string) $row['extracted_answer'] : null,
            'step_feedback' => isset($row['step_feedback']) ? (string) $row['step_feedback'] : $item->step_feedback,
            'score' => $score,
            'max_score' => 1,
            'is_correct' => (bool) ($row['is_correct'] ?? ($score === 1)),
            'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : null,
            'needs_review' => (bool) ($row['needs_review'] ?? false),
            'source_page' => $sourcePage,
            'source_image_path' => $sourceImagePath,
        ]);

        $fresh = $submission->fresh(['items']);
        $fresh->update([
            'score' => $fresh->items->sum('score'),
            'max_score' => $fresh->items->count(),
            'status' => WrittenSubmission::STATUS_GRADED,
            'grading_error' => null,
            'graded_at' => $fresh->graded_at ?? now(),
        ]);

        $this->correctionQueue->syncFromWrittenSubmission($fresh);

        return $item->fresh();
    }

    /**
     * @param  list<Question>  $questions
     * @return list<array<string, mixed>>
     */
    private function questionPayload(array $questions, ?int $forceNumber = null): array
    {
        return collect($questions)->values()->map(function (Question $question, int $index) use ($forceNumber) {
            $correct = $question->isMcq()
                ? $this->pdfService->plainText($question->options->firstWhere('is_correct', true)?->option_text)
                : $question->blankAnswer?->correct_answer;

            return [
                'number' => $forceNumber ?? ($index + 1),
                'text' => $this->pdfService->plainText($question->question_text),
                'type' => $question->type,
                'answer_format' => $question->blankAnswer?->answer_format,
                'correct_answer' => $correct,
                'method_hint' => $this->pdfService->plainText($question->method_hint),
                'explanation' => $this->pdfService->plainText($question->explanation),
            ];
        })->all();
    }

    /**
     * @param  list<array{type: string, image_url: array{url: string}}>  $imageParts
     * @return array<string, mixed>
     */
    private function callGradingModel(string $apiKey, string $prompt, array $imageParts): array
    {
        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.grading_model', 'gpt-4o-mini'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You grade handwritten school maths homework from photos/PDF pages. Read carefully. Return strict JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => array_merge(
                            [['type' => 'text', 'text' => $prompt]],
                            $imageParts,
                        ),
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('AI grading failed: '.$response->body());
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('AI grading returned an empty response.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $payload;
    }

    /**
     * @return array{
     *     parts: list<array{type: string, image_url: array{url: string}}>,
     *     pages: list<array{index: int, path: string}>
     * }
     */
    private function prepareGradingImages(WrittenSubmission $submission, bool $keepExistingPages = false): array
    {
        $pagesDirectory = 'written-submissions/'.$submission->id.'/grading-pages';

        if (! $keepExistingPages || ! Storage::disk('public')->exists($pagesDirectory)) {
            Storage::disk('public')->deleteDirectory($pagesDirectory);
            Storage::disk('public')->makeDirectory($pagesDirectory);
        }

        $existing = collect(Storage::disk('public')->files($pagesDirectory))
            ->filter(fn (string $path) => preg_match('/\.(jpe?g|png|webp|gif)$/i', $path) === 1)
            ->sort()
            ->values();

        if ($keepExistingPages && $existing->isNotEmpty()) {
            $pages = $existing->values()->map(fn (string $path, int $index) => [
                'index' => $index + 1,
                'path' => $path,
            ])->all();

            return [
                'parts' => collect($pages)->map(fn (array $page) => $this->imagePartFromPath($page['path']))->all(),
                'pages' => $pages,
            ];
        }

        $pages = [];
        $pageIndex = 0;

        foreach ($submission->upload_paths ?? [] as $path) {
            $absolute = Storage::disk('public')->path($path);
            if (! is_file($absolute)) {
                continue;
            }

            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = mime_content_type($absolute) ?: 'image/jpeg';

            if ($extension === 'pdf' || str_contains($mime, 'pdf')) {
                if (! $this->pageImageService->isAvailable()) {
                    throw new \RuntimeException(
                        'PDF answer sheets need Ghostscript or poppler-utils (pdftoppm) on the server. Install one of them, or upload a JPG/PNG photo instead.',
                    );
                }

                $tempDirectory = 'temp/written-grading/'.$submission->id.'/'.md5($path);
                $pagePaths = $this->pageImageService->renderPages($path, $tempDirectory);
                $pagePaths = array_slice($pagePaths, 0, self::MAX_GRADING_PAGES);

                foreach ($pagePaths as $pagePath) {
                    $pageIndex++;
                    $extensionOut = pathinfo($pagePath, PATHINFO_EXTENSION) ?: 'png';
                    $storedPath = $pagesDirectory.'/page-'.str_pad((string) $pageIndex, 2, '0', STR_PAD_LEFT).'.'.$extensionOut;
                    Storage::disk('public')->put($storedPath, Storage::disk('public')->get($pagePath));
                    $pages[] = ['index' => $pageIndex, 'path' => $storedPath];
                }

                Storage::disk('public')->deleteDirectory($tempDirectory);

                continue;
            }

            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
                && ! str_starts_with($mime, 'image/')) {
                throw new \RuntimeException(
                    'Unsupported file type for AI grading. Upload JPG, PNG, WEBP, or PDF.',
                );
            }

            $pageIndex++;
            $storedPath = $pagesDirectory.'/page-'.str_pad((string) $pageIndex, 2, '0', STR_PAD_LEFT).'.'.$extension;
            Storage::disk('public')->put($storedPath, Storage::disk('public')->get($path));
            $pages[] = ['index' => $pageIndex, 'path' => $storedPath];
        }

        $pages = array_slice($pages, 0, self::MAX_GRADING_PAGES);

        return [
            'parts' => collect($pages)->map(fn (array $page) => $this->imagePartFromPath($page['path']))->all(),
            'pages' => $pages,
        ];
    }

    /**
     * @return array{type: string, image_url: array{url: string}}
     */
    private function imagePartFromPath(string $path): array
    {
        $gradingPath = $this->uploadOptimizer->gradingCopyPath($path);
        $absolute = Storage::disk('public')->path($gradingPath);
        $mime = mime_content_type($absolute) ?: 'image/jpeg';
        $encoded = base64_encode((string) file_get_contents($absolute));

        return [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:{$mime};base64,{$encoded}",
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function buildPrompt(array $questions, string $setCode, int $pageCount): string
    {
        $lines = [
            "Grade handwritten work for sheet {$setCode}.",
            'The question sheet has no answer spaces. Students write answers on a separate answer sheet.',
            'Match each answer to a question by the label written on the answer sheet (Q1, Q2, Q3, …).',
            'Answers should appear on the sheet in ascending question order (Q1 at the top, then Q2, then Q3, …).',
            "There are {$pageCount} image page(s) attached in order. Use 1-based source_page for the page where the labelled final answer appears.",
            'Earlier questions (Q1, Q2…) are usually on earlier pages; later questions are usually on later pages. Do not put every answer on page 1 unless they truly all appear there.',
            'Read the uploaded photo(s) of the answer sheet. Ignore rough-work pages unless they show the labelled final answer.',
            'For each question number, extract the student answer, check working/method where visible, and compare to the correct answer.',
            '',
            'OCR / reading rules (critical):',
            '- Transcribe the FINAL labelled answer only (after the last "=" for that question), not crossed-out working.',
            '- An equals sign "=" is two short horizontal strokes. Never treat either stroke of "=" as a minus sign on the answer.',
            '- A fraction bar in 1/2 is horizontal through the middle of the digits. It is NOT a minus. Do not read 1/2 as -1/2.',
            '- A true negative answer has a clear minus/dash to the LEFT of the whole answer (e.g. -1/2), separate from "=".',
            '- Prefer forms like 1/2, 0.5, or mixed numbers. If unsure between 1/2 and -1/2, choose the unsigned form that matches the working and set needs_review=true.',
            '- If handwriting is unclear, set needs_review=true and lower confidence.',
            '',
            'Return JSON with keys:',
            '- summary: short overall feedback for the student/parent',
            '- items: array of objects with question_number, extracted_answer, step_feedback, score (0 or 1), is_correct (boolean), confidence (0 to 1), needs_review (boolean), source_page (1-based page index among the attached images)',
            '',
            'Questions and marking scheme:',
        ];

        foreach ($questions as $question) {
            $lines[] = "Q{$question['number']}: {$question['text']}";
            $lines[] = "Type: {$question['type']}";
            if (! empty($question['answer_format'])) {
                $lines[] = "Expected answer format: {$question['answer_format']}";
            }
            $lines[] = "Correct answer: {$question['correct_answer']}";
            if ($question['method_hint']) {
                $lines[] = "Method hint: {$question['method_hint']}";
            }
            if ($question['explanation']) {
                $lines[] = "Marking notes: {$question['explanation']}";
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $question
     */
    private function buildSingleQuestionPrompt(array $question, string $setCode, int $pageCount): string
    {
        $lines = [
            "Re-read ONLY Q{$question['number']} for sheet {$setCode}.",
            "There are {$pageCount} image page(s) attached. Find the labelled final answer for this question.",
            'OCR rules: do not confuse "=" with a minus; do not read fraction 1/2 as -1/2; transcribe the final answer after "=".',
            'Return JSON with keys: summary (short note), items: [{ question_number, extracted_answer, step_feedback, score (0 or 1), is_correct, confidence, needs_review, source_page }].',
            '',
            "Q{$question['number']}: {$question['text']}",
            "Type: {$question['type']}",
        ];

        if (! empty($question['answer_format'])) {
            $lines[] = "Expected answer format: {$question['answer_format']}";
        }

        $lines[] = "Correct answer: {$question['correct_answer']}";

        if ($question['method_hint']) {
            $lines[] = "Method hint: {$question['method_hint']}";
        }

        if ($question['explanation']) {
            $lines[] = "Marking notes: {$question['explanation']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<Question>  $questions
     * @param  array<string, mixed>  $payload
     * @param  list<array{index: int, path: string}>  $pages
     */
    private function persistResults(
        WrittenSubmission $submission,
        SetAssignment $assignment,
        array $questions,
        array $payload,
        array $pages,
        bool $sendEmail = true,
    ): WrittenSubmission {
        $submission->items()->delete();

        $items = collect($payload['items'] ?? []);
        $totalScore = 0;
        $maxScore = count($questions);

        foreach ($questions as $index => $question) {
            $number = $index + 1;
            $row = $items->firstWhere('question_number', $number)
                ?? $items->get($index)
                ?? [];

            $score = (int) ($row['score'] ?? 0);
            $score = max(0, min(1, $score));
            $totalScore += $score;
            $sourcePage = $this->resolveSourcePage($row, $pages, null, $number, $maxScore);
            $sourceImagePath = $sourcePage ? ($pages[$sourcePage - 1]['path'] ?? null) : null;

            WrittenSubmissionItem::create([
                'written_submission_id' => $submission->id,
                'question_id' => $question->id,
                'question_number' => $number,
                'extracted_answer' => isset($row['extracted_answer']) ? (string) $row['extracted_answer'] : null,
                'step_feedback' => isset($row['step_feedback']) ? (string) $row['step_feedback'] : null,
                'score' => $score,
                'max_score' => 1,
                'is_correct' => (bool) ($row['is_correct'] ?? ($score === 1)),
                'confidence' => isset($row['confidence']) ? (float) $row['confidence'] : null,
                'needs_review' => (bool) ($row['needs_review'] ?? false),
                'source_page' => $sourcePage,
                'source_image_path' => $sourceImagePath,
            ]);
        }

        $submission->update([
            'status' => WrittenSubmission::STATUS_GRADED,
            'score' => $totalScore,
            'max_score' => $maxScore,
            'ai_summary' => isset($payload['summary']) ? (string) $payload['summary'] : null,
            'grading_error' => null,
            'graded_at' => now(),
        ]);

        WrittenSubmissionProgress::clear($submission->id);

        $assignment->update([
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        $submission = $submission->fresh(['items']);

        if ($sendEmail) {
            WrittenSubmissionMailer::sendGraded($submission);
        }

        $this->correctionQueue->syncFromWrittenSubmission($submission);

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{index: int, path: string}>  $pages
     */
    private function resolveSourcePage(
        array $row,
        array $pages,
        ?int $fallback,
        ?int $questionNumber = null,
        int $questionCount = 1,
    ): ?int {
        $pageCount = count($pages);

        if ($pageCount === 0) {
            return null;
        }

        if ($pageCount === 1) {
            return 1;
        }

        $candidate = isset($row['source_page']) ? (int) $row['source_page'] : 0;

        if ($candidate >= 1 && $candidate <= $pageCount) {
            return $candidate;
        }

        if ($fallback && $fallback >= 1 && $fallback <= $pageCount) {
            return $fallback;
        }

        if ($questionNumber && $questionCount > 0) {
            // Spread questions across pages when the model omits source_page.
            return max(1, min($pageCount, (int) ceil(($questionNumber * $pageCount) / max($questionCount, 1))));
        }

        return null;
    }
}
