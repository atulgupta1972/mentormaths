<?php

namespace Tests\Unit;

use App\Models\MmmCard;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardEquationNormalizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_style_equations_are_accepted(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $service = app(MmmCardService::class);

        $equations = [
            '2x + (-20 + x) = 25',
            '2x+(-20+x)=25',
            '2x + (x - 20) = 25',
            '2x+(x-20)=25',
            '2x - 20 + x = 25',
            '2x+(−20+x)=25', // unicode minus
            '3x - 20 = 25',
            '3x-20=25',
            '25 = 3x - 20',
        ];

        foreach ($equations as $equation) {
            $result = $service->checkSubmission($card, $exercise, [
                'rows' => [
                    'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                    'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => 'x-20'],
                ],
                'grand_total' => '25',
                'equation' => $equation,
                'x_value' => '15',
            ]);

            $this->assertTrue(
                $result['ok'],
                'Failed for ['.$equation.']: '.json_encode($result['cell_results']),
            );
        }
    }
}
