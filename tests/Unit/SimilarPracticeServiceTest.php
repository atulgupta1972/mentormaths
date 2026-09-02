<?php

namespace Tests\Unit;

use App\Services\SimilarPracticeService;
use Tests\TestCase;

class SimilarPracticeServiceTest extends TestCase
{
    public function test_normalize_variants_from_ai_payload(): void
    {
        $service = app(SimilarPracticeService::class);
        $sources = [[
            'question_id' => 10,
            'number' => 2,
            'type' => 'mcq',
            'question_text' => 'What is 5 + 3?',
            'options' => ['6', '7', '8', '9'],
        ]];

        $payload = [
            'variants' => [[
                'source_question_id' => 10,
                'source_number' => 2,
                'type' => 'mcq',
                'question' => 'What is 7 + 4?',
                'options' => ['9', '10', '11', '12'],
                'correct_index' => 2,
                'method_hint' => 'Add the numbers.',
            ]],
        ];

        $method = new \ReflectionMethod($service, 'normalizeVariants');
        $variants = $method->invoke($service, $payload, $sources);

        $this->assertCount(1, $variants);
        $this->assertSame('What is 7 + 4?', $variants[0]['question']);
        $this->assertSame(2, $variants[0]['correct_index']);
    }

    public function test_public_variants_hide_answers(): void
    {
        $service = app(SimilarPracticeService::class);

        $public = $service->publicVariants([[
            'source_number' => 1,
            'type' => 'mcq',
            'question' => 'Test?',
            'options' => ['A', 'B'],
            'correct_index' => 1,
            'correct_answer' => 'B',
        ]]);

        $this->assertArrayNotHasKey('correct_index', $public[0]);
        $this->assertArrayNotHasKey('correct_answer', $public[0]);
        $this->assertSame(['A', 'B'], $public[0]['options']);
    }
}
