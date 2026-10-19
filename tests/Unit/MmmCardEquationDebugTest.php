<?php

namespace Tests\Unit;

use App\Models\MmmCard;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardEquationDebugTest extends TestCase
{
    use RefreshDatabase;

    public function test_exact_ui_equation_and_table_from_screenshot(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $service = app(MmmCardService::class);

        $submission = [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => '-1(20-x)'],
            ],
            'grand_total' => '25',
            'x_value' => '15',
        ];

        $result = $service->checkSubmission($card, $exercise, $submission);

        $this->assertTrue($result['ok'], 'cell_results='.json_encode($result['cell_results']));
        $this->assertArrayNotHasKey('equation', $result['cell_results']);
    }
}
