<?php

namespace App\Services;

use App\Models\ContentUploadTask;
use App\Models\ContentVerificationCheck;
use App\Models\ContentVerificationRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ContentAiVerificationService
{
    public const VERDICT_APPROVE = 'approve';

    public const VERDICT_SKIP = 'skip';

    public const VERDICT_NEEDS_FIX = 'needs_fix';

    public const VERDICT_NEEDS_DIAGRAM = 'needs_diagram';

    public function __construct(
        private ContentVerificationService $verification,
    ) {}

    /**
     * Review pending MCQs with OpenAI, auto-apply high-confidence approve/skip, leave the rest for humans.
     *
     * @return array{
     *     reviewed: int,
     *     approved: int,
     *     skipped: int,
     *     needs_attention: int,
     *     attention: list<array{question_id: int, number: int, verdict: string, confidence: string, note: string}>
     * }
     */
    public function reviewAndApply(ContentUploadTask $task, ContentVerificationRun $run, User $user): array
    {
        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('OPENAI_API_KEY is not configured on the server.');
        }

        $payload = $this->verification->forTask($task, $user);
        $pending = collect($payload['questions'])
            ->filter(fn (array $row) => ! $row['is_verified'])
            ->values();

        if ($pending->isEmpty()) {
            return [
                'reviewed' => 0,
                'approved' => 0,
                'skipped' => 0,
                'needs_attention' => 0,
                'attention' => [],
            ];
        }

        $batchSize = max(5, min(20, (int) config('services.openai.content_verification_batch_size', 12)));
        $reviews = [];

        foreach ($pending->chunk($batchSize) as $chunk) {
            foreach ($this->reviewBatch($apiKey, $chunk->values()->all()) as $questionId => $review) {
                $reviews[(int) $questionId] = $review;
            }
        }

        $approvedIds = [];
        $skippedPairs = [];
        $attention = [];

        foreach ($pending as $row) {
            $review = $reviews[$row['question_id']] ?? null;

            if ($review === null) {
                $this->persistAiNote(
                    $run,
                    (int) $row['question_id'],
                    self::VERDICT_NEEDS_FIX,
                    'low',
                    'AI did not return a review for this question.',
                );
                $attention[] = $this->attentionItem($row, self::VERDICT_NEEDS_FIX, 'low', 'AI did not return a review for this question.');

                continue;
            }

            $verdict = $review['verdict'];
            $confidence = $review['confidence'];
            $note = $review['note'];

            $this->persistAiNote($run, (int) $row['question_id'], $verdict, $confidence, $note);

            $action = $this->decideAutoAction($row, $verdict, $confidence);

            if ($action === 'approved') {
                $approvedIds[] = (int) $row['question_id'];
            } elseif ($action === 'skipped') {
                $skippedPairs[] = [
                    'question_id' => (int) $row['question_id'],
                    'note' => $note,
                ];
            } else {
                $attention[] = $this->attentionItem($row, $verdict, $confidence, $note);
            }
        }

        if ($approvedIds !== []) {
            $this->verification->markVerifiedBatch($run, $approvedIds, $user);
        }

        foreach ($skippedPairs as $pair) {
            $this->verification->skipQuestion($run, $pair['question_id'], $user, $pair['note']);
        }

        return [
            'reviewed' => $pending->count(),
            'approved' => count($approvedIds),
            'skipped' => count($skippedPairs),
            'needs_attention' => count($attention),
            'attention' => $attention,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array{verdict: string, confidence: string, note: string}>
     */
    private function reviewBatch(string $apiKey, array $rows): array
    {
        $questions = [];

        foreach ($rows as $row) {
            $options = collect($row['options'] ?? [])->map(fn (array $opt) => [
                'letter' => $opt['letter'] ?? '',
                'text' => $opt['option_text'] ?? '',
                'marked_correct' => (bool) ($opt['is_correct'] ?? false),
            ])->all();

            $questions[] = [
                'question_id' => (int) $row['question_id'],
                'number' => (int) $row['number'],
                'question_text' => (string) ($row['question_text'] ?? ''),
                'options' => $options,
                'correct_letter' => $row['correct_letter'] ?? null,
                'hint' => (string) ($row['method_hint'] ?? ''),
                'explanation' => (string) ($row['explanation'] ?? ''),
                'difficulty' => (string) ($row['difficulty'] ?? ''),
                'has_diagram' => (bool) ($row['has_diagram'] ?? false),
                'needs_figure_heuristic' => (bool) ($row['needs_figure'] ?? false),
            ];
        }

        $prompt = $this->buildPrompt($questions);

        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.content_verification_model', config('services.openai.grading_model', 'gpt-4o-mini')),
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.1,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You QA school maths MCQs for an Indian CBSE/ICSE learning app. Be strict about wrong answers. Return strict JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Content AI verification failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('AI verification failed: '.$response->body());
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('AI verification returned an empty response.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $normalized = $this->normalizeReviews($payload, $rows);

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     */
    private function buildPrompt(array $questions): string
    {
        $json = json_encode(['questions' => $questions], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return <<<PROMPT
Review each MCQ for upload quality. For every question return one verdict.

Verdicts:
- approve: clear stem, exactly one sensible correct option, maths answer is correct, options not broken, diagram OK if needed (has_diagram true OR no figure required).
- skip: irrelevant / off-topic / not usable for this syllabus chapter (do not pay for these).
- needs_fix: wrong answer, ambiguous stem, broken options, empty text, or poor quality that a human must edit.
- needs_diagram: question clearly requires a figure/graph and has_diagram is false.

Confidence: high | medium | low
Use high only when you are sure.

Return JSON:
{
  "items": [
    {
      "question_id": 123,
      "verdict": "approve|skip|needs_fix|needs_diagram",
      "confidence": "high|medium|low",
      "note": "short reason"
    }
  ]
}

Questions:
{$json}
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array{verdict: string, confidence: string, note: string}>
     */
    private function normalizeReviews(array $payload, array $rows): array
    {
        $allowedVerdicts = [
            self::VERDICT_APPROVE,
            self::VERDICT_SKIP,
            self::VERDICT_NEEDS_FIX,
            self::VERDICT_NEEDS_DIAGRAM,
        ];
        $allowedConfidence = ['high', 'medium', 'low'];
        $knownIds = collect($rows)->map(fn (array $row) => (int) $row['question_id'])->all();
        $out = [];

        foreach ($payload['items'] ?? [] as $item) {
            if (! is_array($item)) {
                continue;
            }

            $questionId = (int) ($item['question_id'] ?? 0);
            if ($questionId <= 0 || ! in_array($questionId, $knownIds, true)) {
                continue;
            }

            $verdict = strtolower(trim((string) ($item['verdict'] ?? '')));
            $confidence = strtolower(trim((string) ($item['confidence'] ?? 'low')));
            $note = trim((string) ($item['note'] ?? ''));

            if (! in_array($verdict, $allowedVerdicts, true)) {
                $verdict = self::VERDICT_NEEDS_FIX;
                $confidence = 'low';
                $note = $note !== '' ? $note : 'Unrecognised AI verdict.';
            }

            if (! in_array($confidence, $allowedConfidence, true)) {
                $confidence = 'low';
            }

            if ($note === '') {
                $note = 'Reviewed by AI.';
            }

            $out[$questionId] = [
                'verdict' => $verdict,
                'confidence' => $confidence,
                'note' => mb_substr($note, 0, 500),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function decideAutoAction(array $row, string $verdict, string $confidence): ?string
    {
        if ($confidence !== 'high') {
            return null;
        }

        $hasDiagram = (bool) ($row['has_diagram'] ?? false);

        if ($verdict === self::VERDICT_APPROVE) {
            if (($row['needs_figure'] ?? false) && ! $hasDiagram) {
                return null;
            }

            return 'approved';
        }

        if ($verdict === self::VERDICT_SKIP) {
            return 'skipped';
        }

        if ($verdict === self::VERDICT_NEEDS_DIAGRAM && $hasDiagram) {
            return 'approved';
        }

        return null;
    }

    private function persistAiNote(
        ContentVerificationRun $run,
        int $questionId,
        string $verdict,
        string $confidence,
        string $note,
    ): void {
        $check = ContentVerificationCheck::query()->firstOrCreate(
            [
                'content_verification_run_id' => $run->id,
                'question_id' => $questionId,
            ],
            ['diagram_note' => 'No diagram needed'],
        );

        $check->update([
            'ai_verdict' => $verdict,
            'ai_confidence' => $confidence,
            'ai_note' => $note,
            'ai_reviewed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{question_id: int, number: int, verdict: string, confidence: string, note: string}
     */
    private function attentionItem(array $row, string $verdict, string $confidence, string $note): array
    {
        return [
            'question_id' => (int) $row['question_id'],
            'number' => (int) $row['number'],
            'verdict' => $verdict,
            'confidence' => $confidence,
            'note' => $note,
        ];
    }
}
