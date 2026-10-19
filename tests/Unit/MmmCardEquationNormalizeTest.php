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
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();
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
