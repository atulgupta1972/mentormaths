<?php

namespace App\Services;

use App\Models\Question;
use App\Models\SetAttempt;
use App\Support\AnswerValidationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SimilarPracticeService
{
    private const MAX_VARIANTS = 15;

    public function __construct(
        private AnswerValidationService $answers,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function wrongSourceItems(SetAttempt $attempt): array
    {
        $attempt->loadMissing([
            'answers.question.options',
            'answers.question.blankAnswer',
            'guidedQuestions.question.options',
            'guidedQuestions.question.blankAnswer',
            'assignment.practiceSet.questions.options',
            'assignment.practiceSet.questions.blankAnswer',
        ]);

        if ($attempt->isGuided()) {
            return $attempt->guidedQuestions
                ->sortBy('sort_order')
                ->values()
                ->map(function ($guided, int $index) {
                    if ($guided->final_is_correct) {
                        return null;
                    }

                    $question = $guided->question;
                    if (! $question) {
                        return null;
                    }

                    return $this->sourceItemFromQuestion($question, $index + 1);
                })
                ->filter()
                ->values()
                ->all();
        }

        $answersByQuestion = $attempt->answers->keyBy('question_id');
        $items = [];

        foreach ($attempt->assignment->practiceSet->questions as $index => $question) {
            $answer = $answersByQuestion->get($question->id);

            if ($answer?->is_correct) {
                continue;
            }

            $items[] = $this->sourceItemFromQuestion($question, $index + 1);
        }

        return $items;
    }

    /**
     * @return array{variants: list<array<string, mixed>>, wrong_count: int}
     */
    public function generate(SetAttempt $attempt): array
    {
        if ($attempt->status !== SetAttempt::STATUS_SUBMITTED) {
            throw new InvalidArgumentException('Similar practice is only available after submission.');
        }

        $stored = $attempt->similar_practice_variants;

        if (is_array($stored) && ($stored['variants'] ?? []) !== []) {
            return [
                'variants' => $this->publicVariants($stored['variants']),
                'wrong_count' => (int) ($stored['wrong_count'] ?? count($stored['variants'])),
            ];
        }

        $sources = $this->wrongSourceItems($attempt);

        if ($sources === []) {
            $attempt->update(['similar_practice_variants' => ['variants' => [], 'wrong_count' => 0]]);

            return ['variants' => [], 'wrong_count' => 0];
        }

        $apiKey = config('services.openai.api_key');

        if (! $apiKey) {
            throw new \RuntimeException('Similar practice is not configured on the server yet.');
        }

        $sources = array_slice($sources, 0, self::MAX_VARIANTS);
        $variants = $this->requestVariants($apiKey, $sources);
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'wrong_count' => count($sources),
            'variants' => $variants,
        ];

        $attempt->update(['similar_practice_variants' => $payload]);

        return [
            'variants' => $this->publicVariants($variants),
            'wrong_count' => count($sources),
        ];
    }

    /**
     * @return array{correct: bool, message: string}
     */
    public function checkAnswer(
        SetAttempt $attempt,
        int $variantIndex,
        ?int $optionIndex = null,
        ?string $answerText = null,
    ): array {
        $stored = $attempt->similar_practice_variants;

        if (! is_array($stored)) {
            throw new InvalidArgumentException('Generate similar practice first.');
        }

        $variants = $stored['variants'] ?? [];

        if (! isset($variants[$variantIndex])) {
            throw new InvalidArgumentException('Practice question not found.');
        }

        $variant = $variants[$variantIndex];
        $type = $variant['type'] ?? Question::TYPE_MCQ;
        $isCorrect = false;

        if ($type === Question::TYPE_FILL_IN_BLANK) {
            if (! filled($answerText)) {
                throw new InvalidArgumentException('Enter an answer before submitting.');
            }

            $isCorrect = $this->answers->matchesFormattedAnswer(
                (string) ($variant['answer_format'] ?? 'text'),
                (string) ($variant['correct_answer'] ?? ''),
                $variant['decimal_places'] ?? null,
                $answerText,
            );
        } else {
            if ($optionIndex === null) {
                throw new InvalidArgumentException('Select an option before submitting.');
            }

            $isCorrect = (int) $optionIndex === (int) ($variant['correct_index'] ?? -1);
        }

        if ($isCorrect) {
            $variants[$variantIndex]['student_correct'] = true;
            $stored['variants'] = $variants;
            $attempt->update(['similar_practice_variants' => $stored]);
        }

        return [
            'correct' => $isCorrect,
            'message' => $isCorrect
                ? 'Correct! Well done.'
                : 'Not quite — try again with different working.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @return list<array<string, mixed>>
     */
    public function publicVariants(array $variants): array
    {
        return collect($variants)
            ->values()
            ->map(function (array $variant, int $index) {
                $type = $variant['type'] ?? Question::TYPE_MCQ;
                $row = [
                    'index' => $index,
                    'source_number' => $variant['source_number'] ?? null,
                    'type' => $type,
                    'question' => $variant['question'] ?? $variant['question_text'] ?? '',
                    'method_hint' => $variant['method_hint'] ?? null,
                    'student_correct' => (bool) ($variant['student_correct'] ?? false),
                ];

                if ($type === Question::TYPE_FILL_IN_BLANK) {
                    $row['answer_format'] = $variant['answer_format'] ?? 'text';
                    $row['answer_format_label'] = $this->answers->formatLabel($variant['answer_format'] ?? null);
                } else {
                    $row['options'] = collect($variant['options'] ?? [])
                        ->values()
                        ->map(fn ($option) => is_array($option) ? ($option['text'] ?? $option['option_text'] ?? '') : (string) $option)
                        ->all();
                }

                return $row;
            })
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function requestVariants(string $apiKey, array $sources): array
    {
        $prompt = $this->buildPrompt($sources);

        $response = Http::withToken($apiKey)
            ->timeout(180)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.openai.similar_practice_model', config('services.openai.content_verification_model', 'gpt-4o-mini')),
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0.4,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You create similar school maths practice questions for Indian CBSE/ICSE students. Return strict JSON only.',
                    ],
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Similar practice generation failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Could not generate similar practice right now. Try again in a moment.');
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (! is_string($content) || $content === '') {
            throw new \RuntimeException('AI returned an empty response.');
        }

        /** @var array<string, mixed> $payload */
        $payload = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $this->normalizeVariants($payload, $sources);
    }

    /**
     * @param  list<array<string, mixed>>  $sources
     */
    private function buildPrompt(array $sources): string
    {
        $lines = [
            'Create ONE similar practice question for EACH source below.',
            'Rules:',
            '- Same skill and difficulty; use DIFFERENT numbers/values (never copy the source numbers).',
            '- Keep the same question type (mcq stays mcq; fill_in_blank stays fill_in_blank).',
            '- MCQ: exactly 4 options unless the source had 8, then use 8 options.',
            '- fill_in_blank: question must contain ____ and include answer_format + correct_answer.',
            '- Include method_hint (theory only, no final answer) for each variant.',
            '',
        ];

        foreach ($sources as $source) {
            $type = $source['type'] === Question::TYPE_FILL_IN_BLANK ? 'fill_in_blank' : 'mcq';
            $lines[] = "Source #{$source['number']} (source_question_id={$source['question_id']}, type={$type}):";
            $lines[] = $source['question_text'];

            if ($type === 'mcq' && ! empty($source['options'])) {
                foreach ($source['options'] as $optIndex => $optText) {
                    $lines[] = '  '.chr(65 + $optIndex).'. '.$optText;
                }
            }

            if ($type === 'fill_in_blank') {
                $lines[] = '  (answer format: '.$source['answer_format'].' — invent new numbers)';
            }

            $lines[] = '';
        }

        $lines[] = 'Return JSON:';
        $lines[] = '{"variants":[{"source_question_id":101,"source_number":1,"type":"mcq","question":"...","options":["A","B","C","D"],"correct_index":0,"method_hint":"..."},{"source_question_id":102,"source_number":2,"type":"fill_in_blank","question":"... = ____","answer_format":"integer","correct_answer":"12","method_hint":"..."}]}';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function normalizeVariants(array $payload, array $sources): array
    {
        $raw = $payload['variants'] ?? $payload['questions'] ?? [];

        if (! is_array($raw) || $raw === []) {
            throw new InvalidArgumentException('AI did not return any similar practice questions.');
        }

        $bySourceId = collect($sources)->keyBy('question_id');
        $normalized = [];

        foreach ($raw as $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $sourceId = (int) ($variant['source_question_id'] ?? 0);
            $source = $bySourceId->get($sourceId);

            if (! $source) {
                continue;
            }

            $type = strtolower((string) ($variant['type'] ?? ''));

            if ($type === '') {
                $type = ! empty($variant['options']) || isset($variant['correct_index'])
                    ? Question::TYPE_MCQ
                    : Question::TYPE_FILL_IN_BLANK;
            } elseif ($type === 'fill_in_blank') {
                $type = Question::TYPE_FILL_IN_BLANK;
            } else {
                $type = Question::TYPE_MCQ;
            }

            $row = [
                'source_question_id' => $sourceId,
                'source_number' => (int) ($variant['source_number'] ?? $source['number']),
                'type' => $type,
                'question' => trim((string) ($variant['question'] ?? $variant['question_text'] ?? '')),
                'method_hint' => trim((string) ($variant['method_hint'] ?? '')),
                'student_correct' => false,
            ];

            if ($row['question'] === '') {
                continue;
            }

            if ($type === Question::TYPE_FILL_IN_BLANK) {
                $row['answer_format'] = (string) ($variant['answer_format'] ?? $source['answer_format'] ?? 'text');
                $row['correct_answer'] = trim((string) ($variant['correct_answer'] ?? ''));
                $row['decimal_places'] = $variant['decimal_places'] ?? null;

                if ($row['correct_answer'] === '') {
                    continue;
                }
            } else {
                $options = collect($variant['options'] ?? [])
                    ->values()
                    ->map(fn ($option) => is_array($option) ? trim((string) ($option['text'] ?? $option['option_text'] ?? '')) : trim((string) $option))
                    ->filter()
                    ->values()
                    ->all();

                if (count($options) < 2) {
                    continue;
                }

                $row['options'] = $options;
                $row['correct_index'] = max(0, min(count($options) - 1, (int) ($variant['correct_index'] ?? 0)));
            }

            $normalized[] = $row;
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Could not parse similar practice questions from AI response.');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceItemFromQuestion(Question $question, int $number): array
    {
        $question->loadMissing(['options', 'blankAnswer']);

        $item = [
            'question_id' => $question->id,
            'number' => $number,
            'type' => $question->type,
            'question_text' => $question->question_text,
        ];

        if ($question->isMcq()) {
            $item['options'] = $question->options->pluck('option_text')->all();
        } else {
            $item['answer_format'] = $question->blankAnswer?->answer_format ?? 'text';
        }

        return $item;
    }
}
