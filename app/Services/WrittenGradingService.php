<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SetAssignment;
use App\Models\WrittenSubmission;
use App\Models\WrittenSubmissionItem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class WrittenGradingService
{
    public function __construct(
        private WrittenSheetPdfService $pdfService,
        private PdfPageImageService $pageImageService,
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

        $questions = $worksheet->questions->values()->map(function (Question $question, int $index) {
            $correct = $question->isMcq()
                ? $this->pdfService->plainText($question->options->firstWhere('is_correct', true)?->option_text)
                : $question->blankAnswer?->correct_answer;

            return [
                'number' => $index + 1,
                'text' => $this->pdfService->plainText($question->question_text),
                'type' => $question->type,
                'correct_answer' => $correct,
                'method_hint' => $this->pdfService->plainText($question->method_hint),
                'explanation' => $this->pdfService->plainText($question->explanation),
            ];
        })->all();

        $imageParts = $this->buildImageParts($submission);

        if ($imageParts === []) {
            throw new \RuntimeException('Uploaded files could not be read.');
        }

        $prompt = $this->buildPrompt($questions, $worksheet->set_code);

        $response = Http::withToken($apiKey)
            ->timeout(120)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.grading_model', 'gpt-4o-mini'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You grade handwritten school maths homework. Return strict JSON only.',
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

        return $this->persistResults($submission, $assignment, $worksheet->questions->values()->all(), $payload);
    }

    /**
     * @return list<array{type: string, image_url: array{url: string}}>
     */
    private function buildImageParts(WrittenSubmission $submission): array
    {
        $imageParts = [];
        $tempDirs = [];

        try {
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
                            'PDF answer sheets need Ghostscript on the server. Install Ghostscript, or upload a JPG/PNG photo instead.',
                        );
                    }

                    $outputDirectory = 'temp/written-grading/'.$submission->id.'/'.md5($path);
                    $tempDirs[] = $outputDirectory;
                    $pagePaths = $this->pageImageService->renderPages($path, $outputDirectory);

                    foreach ($pagePaths as $pagePath) {
                        $imageParts[] = $this->imagePartFromPath($pagePath);
                    }

                    continue;
                }

                if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
                    && ! str_starts_with($mime, 'image/')) {
                    throw new \RuntimeException(
                        'Unsupported file type for AI grading. Upload JPG, PNG, WEBP, or PDF.',
                    );
                }

                $imageParts[] = $this->imagePartFromPath($path);
            }
        } finally {
            foreach ($tempDirs as $directory) {
                Storage::disk('public')->deleteDirectory($directory);
            }
        }

        return $imageParts;
    }

    /**
     * @return array{type: string, image_url: array{url: string}}
     */
    private function imagePartFromPath(string $path): array
    {
        $absolute = Storage::disk('public')->path($path);
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
    private function buildPrompt(array $questions, string $setCode): string
    {
        $lines = [
            "Grade handwritten work for sheet {$setCode}.",
            'The question sheet has no answer spaces. Students write answers on a separate answer sheet.',
            'Match each answer to a question by the label written on the answer sheet (Q1, Q2, Q3, …).',
            'Read the uploaded photo(s) of the answer sheet. Ignore rough-work pages unless they show the labelled final answer.',
            'For each question number, extract the student answer, check working/method where visible, and compare to the correct answer.',
            'Return JSON with keys:',
            '- summary: short overall feedback for the student/parent',
            '- items: array of objects with question_number, extracted_answer, step_feedback, score (0 or 1), is_correct (boolean), confidence (0 to 1), needs_review (boolean when handwriting or question label unclear)',
            '',
            'Questions and marking scheme:',
        ];

        foreach ($questions as $question) {
            $lines[] = "Q{$question['number']}: {$question['text']}";
            $lines[] = "Type: {$question['type']}";
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
     * @param  list<Question>  $questions
     * @param  array<string, mixed>  $payload
     */
    private function persistResults(
        WrittenSubmission $submission,
        SetAssignment $assignment,
        array $questions,
        array $payload,
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

        $assignment->update([
            'status' => SetAssignment::STATUS_COMPLETED,
        ]);

        return $submission->fresh(['items']);
    }
}
