<?php

namespace Tests\Unit;

use App\Models\MmmCard;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardEquationNormalizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_variable_check_ignores_typed_equation_field(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->create([
            'sort_order' => 83,
            'difficulty' => 'variable',
            'is_demo' => false,
            'question_text' => 'Fixture',
            'answers' => [
                'mode' => 'variable',
                'correct' => [
                    'marks' => '2',
                    'count' => ['x', 'X'],
                    'total' => ['2x', '2X', '2*x'],
                ],
                'wrong' => [
                    'marks' => '-1',
                    'count' => ['20-x', '20 - x'],
                    'total' => ['-20+x', 'x-20', '-(20-x)', '-1*(20-x)', '-1(20-x)'],
                ],
                'grand_total' => '25',
                'x_value' => '15',
            ],
        ]);
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => 'x-20'],
            ],
            'grand_total' => '25',
            'equation' => 'this can be anything',
            'x_value' => '15',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertArrayNotHasKey('equation', $result['cell_results']);
    }
}
