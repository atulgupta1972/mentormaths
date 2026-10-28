<?php

namespace Tests\Unit;

use App\Models\MmmCard;
use App\Models\MmmCardExercise;
use App\Models\SyllabusChapter;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_scoring_practice_is_a_fill_page_of_ten_sums(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $page = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $service = app(MmmCardService::class);

        $this->assertSame('fill_page', $page->answers['mode'] ?? null);
        $this->assertCount(10, $page->answers['play']['items'] ?? []);

        $result = $service->checkSubmission($card, $page, [
            'values' => [
                'q1' => '33',
                'q2' => '19',
                'q3' => '-10',
                'q4' => '8',
                'q5' => '29',
                'q6' => '15',
                'q7' => '3',
                'q8' => '4',
                'q9' => '4',
                'q10' => '3',
            ],
        ]);

        $this->assertTrue($result['all_ok'], json_encode($result['cell_results']));
    }

    public function test_marks_scoring_demo_is_seeded_published(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(2, $card->exercises()->count());
        $this->assertTrue((bool) $card->exercises()->orderBy('sort_order')->first()?->is_demo);
    }

    public function test_variable_twist_correct_marks_as_x(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->create([
            'sort_order' => 80,
            'difficulty' => 'variable',
            'is_demo' => false,
            'question_text' => 'Fixture',
            'answers' => [
                'mode' => 'variable',
                'correct' => ['marks' => ['x', 'X'], 'count' => '8', 'total' => ['8x', '8X']],
                'wrong' => ['marks' => '-1', 'count' => '4', 'total' => '-4'],
                'grand_total' => '20',
                'x_value' => '3',
            ],
        ]);
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => 'x', 'count' => '8', 'total' => '8x'],
                'wrong' => ['marks' => '-1', 'count' => '4', 'total' => '-4'],
            ],
            'grand_total' => '20',
            'x_value' => '3',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['cell_results']));
    }

    public function test_variable_twist_correct_is_two_more_than_wrong(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->create([
            'sort_order' => 81,
            'difficulty' => 'variable',
            'is_demo' => false,
            'question_text' => 'Fixture',
            'answers' => [
                'mode' => 'variable',
                'correct' => ['marks' => ['x+2', '2+x'], 'count' => '6', 'total' => ['6(x+2)', '6x+12', '(x+2)*6']],
                'wrong' => ['marks' => ['-x', '−x'], 'count' => '4', 'total' => ['-4x', '−4x']],
                'grand_total' => '20',
                'x_value' => '4',
            ],
        ]);
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => 'x+2', 'count' => '6', 'total' => '6(x+2)'],
                'wrong' => ['marks' => '-x', 'count' => '4', 'total' => '-4x'],
            ],
            'grand_total' => '20',
            'x_value' => '4',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['cell_results']));
    }

    public function test_variable_sample_accepts_x_table_without_typed_equation(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $this->marksVariableCountExercise($card);
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => 'x-20'],
            ],
            'grand_total' => '25',
            'x_value' => '15',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['cell_results']));
        $this->assertArrayNotHasKey('equation', $result['cell_results']);
    }

    public function test_derived_wrong_count_exercise_checks_out(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->create([
            'sort_order' => 82,
            'difficulty' => 'medium',
            'is_demo' => false,
            'question_text' => 'Fixture',
            'answers' => [
                'correct' => ['marks' => '2', 'count' => '5', 'total' => '10'],
                'wrong' => ['marks' => '-1', 'count' => '2', 'total' => '-2'],
                'grand_total' => '8',
            ],
        ]);
        $service = app(MmmCardService::class);

        // Kabir: 5 correct, attempted 7 → 2 wrong; +2 / −1 → total 8
        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => '5', 'total' => '10'],
                'wrong' => ['marks' => '-1', 'count' => '2', 'total' => '-2'],
            ],
            'grand_total' => '8',
        ]);

        $this->assertTrue($result['ok']);
    }

    public function test_check_submission_marks_wrong_cells(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => '10', 'total' => '20'],
                'wrong' => ['marks' => '-1', 'count' => '5', 'total' => '0'],
            ],
            'grand_total' => '15',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertTrue($result['cell_results']['correct.marks']);
        $this->assertFalse($result['cell_results']['wrong.total']);
        $this->assertTrue($result['cell_results']['grand_total']);
    }

    public function test_check_submission_accepts_full_correct_table(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'correct' => ['marks' => '2', 'count' => '10', 'total' => '20'],
                'wrong' => ['marks' => '−1', 'count' => '5', 'total' => '-5'],
            ],
            'grand_total' => '15',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertTrue(collect($result['cell_results'])->every(fn ($ok) => $ok === true));
    }

    public function test_my_money_card_is_seeded_with_basic_and_ratio_sums(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(15, $card->exercises()->count());
        $this->assertTrue((bool) $card->exercises()->orderBy('sort_order')->first()?->is_demo);
    }

    public function test_my_money_demo_table_checks_out(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['count' => '4', 'total' => '8'],
                'rs5' => ['count' => '3', 'total' => '15'],
                'rs10' => ['count' => '2', 'total' => '20'],
            ],
            'grand_total' => '43',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['cell_results']));
    }

    public function test_my_money_sum3_requires_student_denomination(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 3)->firstOrFail();
        $service = app(MmmCardService::class);

        $payload = $service->playPayload($card, null, 2);
        $this->assertSame('Denomination (in ₹)', $payload['card']['table_schema']['columns'][0]['label'] ?? null);
        $this->assertSame('denomination', $payload['card']['table_schema']['columns'][0]['key'] ?? null);

        $wrong = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '7', 'count' => '8', 'total' => '16'],
                'rs5' => ['denomination' => '5', 'count' => '2', 'total' => '10'],
                'rs10' => ['denomination' => '10', 'count' => '4', 'total' => '40'],
            ],
            'grand_total' => '66',
        ]);
        $this->assertFalse($wrong['ok']);
        $this->assertFalse($wrong['cell_results']['rs2.denomination']);
        $this->assertStringContainsString('Denomination', $wrong['message']);

        $ok = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '₹2', 'count' => '8', 'total' => '16'],
                'rs5' => ['denomination' => '5', 'count' => '2', 'total' => '10'],
                'rs10' => ['denomination' => '10', 'count' => '4', 'total' => '40'],
            ],
            'grand_total' => '66',
        ]);
        $this->assertTrue($ok['ok'], json_encode($ok['cell_results']));
    }

    public function test_my_money_ratio_variable_equation_checks_out(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 6)->firstOrFail();
        $service = app(MmmCardService::class);

        $payload = $service->playPayload($card, null, 5);
        $this->assertSame('Coins (in x)', $payload['card']['table_schema']['columns'][1]['label'] ?? null);
        $this->assertTrue($payload['exercise']['ask_equation'] ?? false);
        $this->assertTrue($payload['exercise']['ask_x_value'] ?? false);
        $this->assertFalse($payload['exercise']['show_auto_equation'] ?? true);

        $wrongEq = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            'equation' => '2x+3x+4x=36',
            'x_value' => '3',
        ]);
        $this->assertFalse($wrongEq['ok']);
        $this->assertFalse($wrongEq['cell_results']['equation']);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            'equation' => '2x+3x+4x=27',
            'x_value' => '3',
        ]);
        $this->assertTrue($result['ok'], json_encode($result['cell_results']));

        $simplified = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            'equation' => '9x=27',
            'x_value' => '3',
        ]);
        $this->assertTrue($simplified['ok'], json_encode($simplified['cell_results']));

        // Multiply-sign × used as variable (or client cleaned × → *) must still pass.
        $timesAsX = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            'equation' => '2×+3×+4×=27',
            'x_value' => '3',
        ]);
        $this->assertTrue($timesAsX['ok'], json_encode($timesAsX['cell_results']));

        $starArtifact = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            'equation' => '2*+3*+4*=27',
            'x_value' => '3',
        ]);
        $this->assertTrue($starArtifact['ok'], json_encode($starArtifact['cell_results']));

        // Encoded payload (WAF-safe) and missing equation field when table already proves it.
        $encoded = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            'coin_equation' => '2x__P__3x__P__4x__E__27',
            'x_value' => '3',
        ]);
        $this->assertTrue($encoded['ok'], json_encode($encoded['cell_results']));

        $tableProves = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '2x', 'count' => '6', 'total' => '12'],
                'rs5' => ['denomination' => '5', 'count_x' => '3x', 'count' => '9', 'total' => '45'],
                'rs10' => ['denomination' => '10', 'count_x' => '4x', 'count' => '12', 'total' => '120'],
            ],
            'grand_count' => '27',
            'grand_total' => '177',
            // equation omitted/stripped — table already has 2x+3x+4x and 27
            'x_value' => '3',
        ]);
        $this->assertTrue($tableProves['ok'], json_encode($tableProves['cell_results']));
        $this->assertTrue($tableProves['cell_results']['equation']);
    }

    public function test_my_money_sum7_fraction_ratio(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $service = app(MmmCardService::class);

        $this->assertStringContainsString('1/2', $exercise->question_text);

        $lcmForm = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '6x', 'count' => '18', 'total' => '36'],
                'rs5' => ['denomination' => '5', 'count_x' => '4x', 'count' => '12', 'total' => '60'],
                'rs10' => ['denomination' => '10', 'count_x' => '3x', 'count' => '9', 'total' => '90'],
            ],
            'grand_count' => '39',
            'grand_total' => '186',
            'equation' => '6x+4x+3x=39',
            'x_value' => '3',
        ]);
        $this->assertTrue($lcmForm['ok'], json_encode($lcmForm['cell_results']));

        $fractionForm = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => 'x/2', 'count' => '18', 'total' => '36'],
                'rs5' => ['denomination' => '5', 'count_x' => 'x/3', 'count' => '12', 'total' => '60'],
                'rs10' => ['denomination' => '10', 'count_x' => 'x/4', 'count' => '9', 'total' => '90'],
            ],
            'grand_count' => '39',
            'grand_total' => '186',
            'equation' => 'x/2+x/3+x/4=39',
            'x_value' => '36',
        ]);
        $this->assertTrue($fractionForm['ok'], json_encode($fractionForm['cell_results']));
    }

    public function test_my_money_sum8_accepts_x_as_rs5_or_rs10(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 8)->firstOrFail();
        $service = app(MmmCardService::class);

        $asRs5 = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '3x', 'count' => '30', 'total' => '60'],
                'rs5' => ['denomination' => '5', 'count_x' => 'x', 'count' => '10', 'total' => '50'],
                'rs10' => ['denomination' => '10', 'count_x' => 'x/2', 'count' => '5', 'total' => '50'],
            ],
            'grand_count' => '45',
            'grand_total' => '160',
            'equation' => '3x+x+x/2=45',
            'x_value' => '10',
        ]);
        $this->assertTrue($asRs5['ok'], json_encode($asRs5['cell_results']));

        $asRs10 = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '6x', 'count' => '30', 'total' => '60'],
                'rs5' => ['denomination' => '5', 'count_x' => '2x', 'count' => '10', 'total' => '50'],
                'rs10' => ['denomination' => '10', 'count_x' => 'x', 'count' => '5', 'total' => '50'],
            ],
            'grand_count' => '45',
            'grand_total' => '160',
            'equation' => '6x+2x+x=45',
            'x_value' => '5',
        ]);
        $this->assertTrue($asRs10['ok'], json_encode($asRs10['cell_results']));

        // Rows reordered: 2, 10, 5 (still correct by denomination).
        $reordered = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => '3x', 'count' => '30', 'total' => '60'],
                'rs5' => ['denomination' => '10', 'count_x' => 'x/2', 'count' => '5', 'total' => '50'],
                'rs10' => ['denomination' => '5', 'count_x' => 'x', 'count' => '10', 'total' => '50'],
            ],
            'grand_count' => '45',
            'grand_total' => '160',
            'equation' => '3x+x+x/2=45',
            'x_value' => '10',
        ]);
        $this->assertTrue($reordered['ok'], json_encode($reordered['cell_results']));
    }

    public function test_my_money_sum9_five_more_five_less(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 9)->firstOrFail();
        $service = app(MmmCardService::class);

        $asRs5 = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => 'x+5', 'count' => '20', 'total' => '40'],
                'rs5' => ['denomination' => '5', 'count_x' => 'x', 'count' => '15', 'total' => '75'],
                'rs10' => ['denomination' => '10', 'count_x' => 'x-5', 'count' => '10', 'total' => '100'],
            ],
            'grand_count' => '45',
            'grand_total' => '215',
            'equation' => '(x+5)+x+(x-5)=45',
            'x_value' => '15',
        ]);
        $this->assertTrue($asRs5['ok'], json_encode($asRs5['cell_results']));

        $asRs10 = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => 'x+10', 'count' => '20', 'total' => '40'],
                'rs5' => ['denomination' => '5', 'count_x' => 'x+5', 'count' => '15', 'total' => '75'],
                'rs10' => ['denomination' => '10', 'count_x' => 'x', 'count' => '10', 'total' => '100'],
            ],
            'grand_count' => '45',
            'grand_total' => '215',
            'equation' => '(x+10)+(x+5)+x=45',
            'x_value' => '10',
        ]);
        $this->assertTrue($asRs10['ok'], json_encode($asRs10['cell_results']));
    }

    public function test_my_money_note_value_ratio_sums(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $service = app(MmmCardService::class);

        $sum10 = $service->checkSubmission($card, $card->exercises()->where('sort_order', 10)->firstOrFail(), [
            'rows' => [
                'rs50' => ['denomination' => '50', 'value_x' => '4x', 'count' => '40', 'total' => '2000'],
                'rs100' => ['denomination' => '100', 'value_x' => '5x', 'count' => '25', 'total' => '2500'],
                'rs500' => ['denomination' => '500', 'value_x' => '6x', 'count' => '6', 'total' => '3000'],
            ],
            'grand_count' => '71',
            'grand_total' => '7500',
            'equation' => '4x+5x+6x=7500',
            'x_value' => '500',
        ]);
        $this->assertTrue($sum10['ok'], json_encode($sum10['cell_results']));

        $sum11 = $service->checkSubmission($card, $card->exercises()->where('sort_order', 11)->firstOrFail(), [
            'rows' => [
                'rs50' => ['denomination' => '50', 'value_x' => '3x', 'count' => '30', 'total' => '1500'],
                'rs100' => ['denomination' => '100', 'value_x' => '4x', 'count' => '20', 'total' => '2000'],
                'rs500' => ['denomination' => '500', 'value_x' => '5x', 'count' => '5', 'total' => '2500'],
            ],
            'grand_count' => '55',
            'grand_total' => '6000',
            'equation' => '3x+4x+5x=6000',
            'x_value' => '500',
        ]);
        $this->assertTrue($sum11['ok'], json_encode($sum11['cell_results']));

        $sum12 = $card->exercises()->where('sort_order', 12)->firstOrFail();
        $asMoneyEq = $service->checkSubmission($card, $sum12, [
            'rows' => [
                'rs50' => ['denomination' => '50', 'count_x' => '2x', 'count' => '4', 'total' => '200'],
                'rs100' => ['denomination' => '100', 'count_x' => '3x', 'count' => '6', 'total' => '600'],
                'rs500' => ['denomination' => '500', 'count_x' => '5x', 'count' => '10', 'total' => '5000'],
            ],
            'grand_count' => '20',
            'grand_total' => '5800',
            'equation' => '2900x=5800',
            'x_value' => '2',
        ]);
        $this->assertTrue($asMoneyEq['ok'], json_encode($asMoneyEq['cell_results']));

        $asExpanded = $service->checkSubmission($card, $sum12, [
            'rows' => [
                'rs50' => ['denomination' => '50', 'count_x' => '2x', 'count' => '4', 'total' => '200'],
                'rs100' => ['denomination' => '100', 'count_x' => '3x', 'count' => '6', 'total' => '600'],
                'rs500' => ['denomination' => '500', 'count_x' => '5x', 'count' => '10', 'total' => '5000'],
            ],
            'grand_count' => '20',
            'grand_total' => '5800',
            'equation' => '50(2x)+100(3x)+500(5x)=5800',
            'x_value' => '2',
        ]);
        $this->assertTrue($asExpanded['ok'], json_encode($asExpanded['cell_results']));
    }

    public function test_my_money_value_relation_sums_accept_flexible_x(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $service = app(MmmCardService::class);
        $sum13 = $card->exercises()->where('sort_order', 13)->firstOrFail();

        $asRs5 = $service->checkSubmission($card, $sum13, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'value_x' => 'x/2', 'count' => '175', 'total' => '350'],
                'rs5' => ['denomination' => '5', 'value_x' => 'x', 'count' => '140', 'total' => '700'],
                'rs10' => ['denomination' => '10', 'value_x' => 'x-100', 'count' => '60', 'total' => '600'],
            ],
            'grand_count' => '375',
            'grand_total' => '1650',
            'equation' => 'x/2+x+(x-100)=1650',
            'x_value' => '700',
        ]);
        $this->assertTrue($asRs5['ok'], json_encode($asRs5['cell_results']));

        $asRs10 = $service->checkSubmission($card, $sum13, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'value_x' => '(x+100)/2', 'count' => '175', 'total' => '350'],
                'rs5' => ['denomination' => '5', 'value_x' => 'x+100', 'count' => '140', 'total' => '700'],
                'rs10' => ['denomination' => '10', 'value_x' => 'x', 'count' => '60', 'total' => '600'],
            ],
            'grand_count' => '375',
            'grand_total' => '1650',
            'equation' => '(x+100)/2+(x+100)+x=1650',
            'x_value' => '600',
        ]);
        $this->assertTrue($asRs10['ok'], json_encode($asRs10['cell_results']));

        $sum14 = $service->checkSubmission($card, $card->exercises()->where('sort_order', 14)->firstOrFail(), [
            'rows' => [
                'rs2' => ['denomination' => '2', 'value_x' => 'x-150', 'count' => '225', 'total' => '450'],
                'rs5' => ['denomination' => '5', 'value_x' => 'x', 'count' => '120', 'total' => '600'],
                'rs10' => ['denomination' => '10', 'value_x' => 'x/2', 'count' => '30', 'total' => '300'],
            ],
            'grand_count' => '375',
            'grand_total' => '1350',
            'equation' => '(x-150)+x+x/2=1350',
            'x_value' => '600',
        ]);
        $this->assertTrue($sum14['ok'], json_encode($sum14['cell_results']));

        $sum15 = $service->checkSubmission($card, $card->exercises()->where('sort_order', 15)->firstOrFail(), [
            'rows' => [
                'rs2' => ['denomination' => '2', 'value_x' => 'x', 'count' => '400', 'total' => '800'],
                'rs5' => ['denomination' => '5', 'value_x' => 'x/2', 'count' => '80', 'total' => '400'],
                'rs10' => ['denomination' => '10', 'value_x' => 'x-200', 'count' => '60', 'total' => '600'],
            ],
            'grand_count' => '540',
            'grand_total' => '1800',
            'equation' => 'x+x/2+(x-200)=1800',
            'x_value' => '800',
        ]);
        $this->assertTrue($sum15['ok'], json_encode($sum15['cell_results']));
    }

    public function test_admin_can_link_card_to_syllabus_chapter(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();

        $year = \App\Models\AcademicYear::query()->create([
            'name' => '2026-27',
            'starts_on' => '2026-03-01',
            'ends_on' => '2027-02-28',
            'is_active' => true,
        ]);
        $board = \App\Models\Board::query()->create(['code' => 'CBSE', 'name' => 'CBSE', 'is_active' => true]);
        $grade = \App\Models\GradeLevel::query()->create(['name' => 'Class 7', 'sort_order' => 7, 'is_active' => true]);
        $subject = \App\Models\Subject::query()->create(['code' => 'MATHS', 'name' => 'Mathematics']);
        $version = \App\Models\SyllabusVersion::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'board_id' => $board->id,
            'subject_id' => $subject->id,
        ]);
        $chapter = SyllabusChapter::query()->create([
            'syllabus_version_id' => $version->id,
            'name' => 'Integers',
            'chapter_number' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.mmm-cards.sync-chapters', $card), [
                'syllabus_chapter_ids' => [$chapter->id],
                'mode' => 'add',
            ])
            ->assertRedirect();

        $this->assertTrue($card->fresh()->syllabusChapters->contains('id', $chapter->id));

        $ctas = app(MmmCardService::class)->ctasForSyllabusChapters(null, [$chapter->id]);
        $this->assertCount(1, $ctas[$chapter->id] ?? []);
        $this->assertSame('Marks Scoring table', $ctas[$chapter->id][0]['heading']);
    }

    public function test_balance_equation_card_is_seeded(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-the-equation')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(MmmCard::KIND_BALANCE_EQUATION, $card->kind);
        $this->assertSame('Balance the equation — Basic 1', $card->heading);
        $this->assertSame(13, $card->exercises()->count());
        $this->assertTrue((bool) $card->exercises()->orderBy('sort_order')->first()?->is_demo);
    }

    public function test_balance_basic_1_ends_with_ten_fill_sums_and_the_next_part(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-the-equation')->firstOrFail();
        $page = $card->exercises()->where('sort_order', 13)->firstOrFail();
        $service = app(MmmCardService::class);

        $this->assertSame('fill_page', $page->answers['mode'] ?? null);
        $this->assertCount(10, $page->answers['play']['items'] ?? []);
        $expected = [
            'balance-the-equation' => 'balance-equation-basic-2',
            'balance-equation-basic-2' => 'balance-equation-basic-3',
            'balance-equation-basic-3' => 'balance-equation-basic-4',
            'balance-equation-basic-4' => 'balance-equation-basic-5',
            'balance-equation-basic-5' => 'balance-equation-basic-6',
            'balance-equation-basic-6' => null,
        ];
        foreach ($expected as $slug => $nextSlug) {
            $part = MmmCard::query()->where('slug', $slug)->firstOrFail();
            $this->assertSame($nextSlug, $service->nextPartCard($part)?->slug, $slug);
            $this->assertSame('Balance the equation', $service->playPayload($part)['card']['series_label']);
        }

        $this->assertTrue($service->checkSubmission($card, $page, ['step_key' => 'q1', 'value' => '12'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $page, ['step_key' => 'q3', 'value' => '7'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $page, ['step_key' => 'q3', 'value' => '-7'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $page, ['step_key' => 'q8', 'value' => '20'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $page, ['step_key' => 'q10', 'value' => '18'])['ok']);
    }

    public function test_balance_equation_play_payload_hides_answers(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-the-equation')->firstOrFail();
        $payload = app(MmmCardService::class)->playPayload($card, null, 0);
        $exercise = $payload['exercise'];

        $this->assertSame('balance', $exercise['play_kind']);
        $this->assertSame('x + 5', $exercise['balance']['given_left']);
        $this->assertArrayNotHasKey('check', $exercise);
        $this->assertArrayNotHasKey('add', $exercise);
    }

    public function test_balance_equation_steps_and_x_on_the_right(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-the-equation')->firstOrFail();
        $service = app(MmmCardService::class);
        $sum1 = $card->exercises()->where('sort_order', 1)->firstOrFail();

        $add = $service->checkSubmission($card, $sum1, [
            'step_key' => 'add',
            'value' => '-5',
        ]);
        $this->assertTrue($add['ok'], json_encode($add));
        $this->assertFalse($add['all_ok']);

        $plusFiveRejected = $service->checkSubmission($card, $sum1, [
            'step_key' => 'add',
            'value' => '5',
        ]);
        $this->assertFalse($plusFiveRejected['ok']);

        $impact = $service->checkSubmission($card, $sum1, [
            'step_key' => 'impact',
            'value' => '5',
        ]);
        $this->assertTrue($impact['ok']);
        $this->assertTrue($impact['all_ok']);

        $sum2 = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $addPlus = $service->checkSubmission($card, $sum2, [
            'step_key' => 'add',
            'value' => '+5',
        ]);
        $this->assertTrue($addPlus['ok'], json_encode($addPlus));

        $sum7 = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $right = $service->checkSubmission($card, $sum7, [
            'step_key' => 'add',
            'value' => '(-30)',
        ]);
        $this->assertTrue($right['ok'], json_encode($right));
        $xRight = $service->checkSubmission($card, $sum7, [
            'step_key' => 'impact',
            'value' => '-10',
        ]);
        $this->assertTrue($xRight['all_ok']);

        $sum10 = $card->exercises()->where('sort_order', 10)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum10, ['step_key' => 'rhs', 'value' => '80'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum10, ['step_key' => 'move', 'value' => '10'])['ok']);
        $done = $service->checkSubmission($card, $sum10, ['step_key' => 'x', 'value' => '90']);
        $this->assertTrue($done['all_ok']);
    }

    public function test_balance_equation_basic_2_is_seeded(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-2')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(MmmCard::KIND_BALANCE_EQUATION, $card->kind);
        $this->assertSame('Balance the equation — Basic 2', $card->heading);
        $this->assertSame(4, $card->exercises()->count());
    }

    public function test_balance_equation_basic_2_divide_then_term_then_bracket(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-2')->firstOrFail();
        $service = app(MmmCardService::class);

        $sum1 = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'divide', 'value' => '2'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum1, ['step_key' => 'divide', 'value' => '7'])['ok']);
        $x1 = $service->checkSubmission($card, $sum1, ['step_key' => 'x', 'value' => '7']);
        $this->assertTrue($x1['all_ok']);

        $payload = $service->playPayload($card, null, 0);
        $this->assertSame('2x', $payload['exercise']['balance']['given_left']);
        $this->assertArrayNotHasKey('check', $payload['exercise']);

        $sum2 = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum2, ['step_key' => 'isolate', 'value' => '8'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum2, ['step_key' => 'term', 'value' => '6'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum2, ['step_key' => 'divide', 'value' => '2'])['ok']);
        $x2 = $service->checkSubmission($card, $sum2, ['step_key' => 'x', 'value' => '3']);
        $this->assertTrue($x2['all_ok']);

        $sum3 = $card->exercises()->where('sort_order', 3)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'bracket', 'value' => '27'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'rhs', 'value' => '-13'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'take', 'value' => '8'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'term', 'value' => '-21'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'divide', 'value' => '2'])['ok']);
        $half = $service->checkSubmission($card, $sum3, ['step_key' => 'x', 'value' => '21/2']);
        $this->assertTrue($half['all_ok'], json_encode($half));
        $decimal = $service->checkSubmission($card, $sum3, ['step_key' => 'x', 'value' => '10.5']);
        $this->assertTrue($decimal['all_ok']);
    }

    public function test_balance_equation_basic_2_fill_page_checks_each_x(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-2')->firstOrFail();
        $service = app(MmmCardService::class);
        $exercise = $card->exercises()->where('sort_order', 4)->firstOrFail();
        $payload = $service->playPayload($card, null, 3);
        $this->assertSame('fill_page', $payload['exercise']['play_kind']);
        $this->assertCount(5, $payload['exercise']['fill_page']['items']);
        $this->assertArrayNotHasKey('check', $payload['exercise']);
        $this->assertStringContainsString('25/5', $payload['exercise']['fill_page']['items'][0]['equation']);

        $first = $service->checkSubmission($card, $exercise, ['step_key' => 'q1', 'value' => '5']);
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['all_ok']);

        $wrong = $service->checkSubmission($card, $exercise, ['step_key' => 'q1', 'value' => '30']);
        $this->assertFalse($wrong['ok']);

        $done = $service->checkSubmission($card, $exercise, [
            'values' => [
                'q1' => '5',
                'q2' => '2',
                'q3' => '7',
                'q4' => '1',
                'q5' => '4',
            ],
        ]);
        $this->assertTrue($done['ok']);
        $this->assertTrue($done['all_ok']);
    }

    public function test_balance_equation_basic_3_multiply_cannot_cut_and_cross(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-3')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(MmmCard::KIND_BALANCE_EQUATION, $card->kind);
        $this->assertSame('Balance the equation — Basic 3', $card->heading);
        $this->assertSame(6, $card->exercises()->count());

        $service = app(MmmCardService::class);

        $sum1 = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'multiply', 'value' => '2'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum1, ['step_key' => 'multiply', 'value' => '10'])['ok']);
        $x1 = $service->checkSubmission($card, $sum1, ['step_key' => 'x', 'value' => '20']);
        $this->assertTrue($x1['all_ok']);

        $payload = $service->playPayload($card, null, 0);
        $this->assertSame('x / 2', $payload['exercise']['balance']['given_left']);
        $this->assertArrayNotHasKey('check', $payload['exercise']);

        $sum2 = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum2, ['step_key' => 'multiply', 'value' => '2'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum2, ['step_key' => 'inner', 'value' => '20'])['ok']);
        $x2 = $service->checkSubmission($card, $sum2, ['step_key' => 'x', 'value' => '18']);
        $this->assertTrue($x2['all_ok']);
        $this->assertFalse($service->checkSubmission($card, $sum2, ['step_key' => 'x', 'value' => '10'])['ok']);

        $sum3 = $card->exercises()->where('sort_order', 3)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum3, [
            'step_key' => 'frac',
            'values' => ['n1' => '5', 'n2' => '5', 'd1' => '3', 'd2' => '3'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum3, [
            'step_key' => 'frac',
            'values' => ['n1' => '3', 'n2' => '3', 'd1' => '5', 'd2' => '5'],
        ])['ok']);
        $x3 = $service->checkSubmission($card, $sum3, ['step_key' => 'x', 'value' => '25/9']);
        $this->assertTrue($x3['all_ok'], json_encode($x3));

        $sum4 = $card->exercises()->where('sort_order', 4)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum4, [
            'step_key' => 'frac',
            'values' => ['n1' => '8', 'n2' => '9', 'd1' => '3', 'd2' => '4'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum4, [
            'step_key' => 'cut',
            'values' => ['cut' => '2', 'cut_b' => '3'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum4, ['step_key' => 'x', 'value' => '12'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum4, ['step_key' => 'x', 'value' => '6'])['all_ok']);

        $sum5 = $card->exercises()->where('sort_order', 5)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum5, [
            'step_key' => 'flip',
            'values' => ['flip_n' => '5', 'flip_d' => '2'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum5, [
            'step_key' => 'flip',
            'values' => ['flip_n' => '2', 'flip_d' => '5'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum5, [
            'step_key' => 'cut',
            'values' => ['cut' => '3', 'cut_b' => '1'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum5, ['step_key' => 'x', 'value' => '3'])['all_ok']);

        $page = $card->exercises()->where('sort_order', 6)->firstOrFail();
        $payload = $service->playPayload($card, null, 5);
        $this->assertSame('fill_page', $payload['exercise']['play_kind']);
        $this->assertCount(5, $payload['exercise']['fill_page']['items']);
        $this->assertTrue($service->checkSubmission($card, $page, ['step_key' => 'q1', 'value' => '5/4'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $page, ['step_key' => 'q2', 'value' => '7'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $page, ['step_key' => 'q1', 'value' => '1'])['ok']);
        $done = $service->checkSubmission($card, $page, [
            'values' => [
                'q1' => '1.25',
                'q2' => '7',
                'q3' => '3',
                'q4' => '6/5',
                'q5' => '5/2',
            ],
        ]);
        $this->assertTrue($done['all_ok'], json_encode($done));
    }

    public function test_balance_equation_basic_4_both_sides_fractions_and_tidy(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-4')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(MmmCard::KIND_BALANCE_EQUATION, $card->kind);
        $this->assertSame('Balance the equation — Basic 4', $card->heading);
        $this->assertSame(4, $card->exercises()->count());

        $service = app(MmmCardService::class);
        $sum1 = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'side', 'value' => 'lhs'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'side', 'value' => 'rhs'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum1, ['step_key' => 'side', 'value' => 'both'])['ok']);

        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'join_l', 'value' => '-x'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum1, ['step_key' => 'join_l', 'value' => 'x'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'take_l', 'value' => '40'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'term_l', 'value' => '-90'])['ok']);
        $left = $service->checkSubmission($card, $sum1, ['step_key' => 'x_l', 'value' => '90']);
        $this->assertTrue($left['all_ok'], json_encode($left));

        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'join_r', 'value' => 'x'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum1, ['step_key' => 'take_r', 'value' => '50'])['ok']);
        $right = $service->checkSubmission($card, $sum1, ['step_key' => 'x_r', 'value' => '90']);
        $this->assertTrue($right['all_ok'], json_encode($right));

        $payload = $service->playPayload($card, null, 0);
        $this->assertSame('2x + 40', $payload['exercise']['balance']['given_left']);
        $this->assertSame('pick_side', $payload['exercise']['balance']['steps'][0]['type']);
        $this->assertArrayHasKey('lhs', $payload['exercise']['balance']['steps'][0]['branches']);
        $this->assertArrayNotHasKey('check', $payload['exercise']);

        $sum2 = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $sum2, [
            'step_key' => 'frac_r',
            'values' => ['rew_r' => '9', 'joined_r' => '7'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum2, [
            'step_key' => 'frac_l',
            'values' => ['mul_l' => '3', 'rew_l' => '7'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum2, [
            'step_key' => 'frac_l',
            'values' => ['mul_l' => '3', 'rew_l' => '9'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum2, ['step_key' => 'nums_r', 'value' => '90'])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum2, [
            'step_key' => 'flip',
            'values' => ['flip_n' => '3', 'flip_d' => '7'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum2, [
            'step_key' => 'flip',
            'values' => ['flip_n' => '7', 'flip_d' => '3'],
        ])['ok']);
        $fraction = $service->checkSubmission($card, $sum2, ['step_key' => 'x', 'value' => '270/49']);
        $this->assertTrue($fraction['all_ok'], json_encode($fraction));

        $sum3 = $card->exercises()->where('sort_order', 3)->firstOrFail();
        $this->assertSame('copy_line', $sum3->answers['play']['steps'][0]['type']);
        $this->assertFalse($service->checkSubmission($card, $sum3, [
            'step_key' => 'open',
            'values' => ['br' => '13', 'rx' => '-2x', 'rnum' => '20'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, [
            'step_key' => 'open',
            'values' => ['br' => '-13', 'rx' => '-2x', 'rnum' => '20'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'sign', 'values' => ['sign' => '13']])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'addn', 'values' => ['addn' => '25']])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, [
            'step_key' => 'move',
            'values' => ['moved_x' => '2x', 'moved_n' => '25'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum3, [
            'step_key' => 'simp',
            'values' => ['coef' => '4x', 'rhs_n' => '-5'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum3, [
            'step_key' => 'simp',
            'values' => ['coef' => '4x', 'rhs_n' => '5'],
        ])['ok']);
        $tidy = $service->checkSubmission($card, $sum3, ['step_key' => 'x', 'values' => ['x' => '-5/4']]);
        $this->assertTrue($tidy['all_ok'], json_encode($tidy));
        $this->assertTrue($service->checkSubmission($card, $sum3, ['step_key' => 'x', 'values' => ['x' => '-1.25']])['all_ok']);

        $page = $card->exercises()->where('sort_order', 4)->firstOrFail();
        $pagePayload = $service->playPayload($card, null, 3);
        $this->assertSame('fill_page', $pagePayload['exercise']['play_kind']);
        $this->assertCount(5, $pagePayload['exercise']['fill_page']['items']);
        $done = $service->checkSubmission($card, $page, [
            'values' => [
                'q1' => '7',
                'q2' => '6',
                'q3' => '14',
                'q4' => '10/3',
                'q5' => '5',
            ],
        ]);
        $this->assertTrue($done['all_ok'], json_encode($done));
    }

    public function test_balance_equation_basic_5_fraction_both_sides(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-5')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(MmmCard::KIND_BALANCE_EQUATION, $card->kind);
        $this->assertSame('Balance the equation — Basic 5', $card->heading);
        $this->assertSame(7, $card->sort_order);
        $this->assertSame(2, $card->exercises()->count());

        $service = app(MmmCardService::class);
        $sum = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $payload = $service->playPayload($card, null, 0);
        $this->assertArrayNotHasKey('check', $payload['exercise']);
        $this->assertSame('note_next', $sum->answers['play']['steps'][0]['type']);
        $this->assertSame('frac_board', $sum->answers['play']['steps'][1]['type']);
        $this->assertSame('6', $sum->answers['play']['steps'][2]['done_board'][0]['terms'][0]['den']);
        $this->assertSame('2x − 4 − 6x + 9', $sum->answers['play']['steps'][3]['action'][0]['terms'][0]['num']);
        $this->assertSame('3', $sum->answers['play']['steps'][2]['action'][0]['terms'][1]['factor']);

        $this->assertTrue($service->checkSubmission($card, $sum, ['step_key' => 'brackets', 'value' => 'ok'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum, ['step_key' => 'brackets', 'value' => 'no'])['ok']);

        $this->assertFalse($service->checkSubmission($card, $sum, [
            'step_key' => 'denoms',
            'values' => [
                'ml1' => '2', 'ml1d' => '3', 'ml2' => '3', 'ml2d' => '3',
                'denL' => '6', 'mr1' => '5', 'mr1d' => '5', 'mr2' => '3', 'mr2d' => '3', 'denR' => '15',
            ],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum, [
            'step_key' => 'denoms',
            'values' => [
                'ml1' => '2', 'ml1d' => '2', 'ml2' => '3', 'ml2d' => '3',
                'denL' => '6', 'mr1' => '5', 'mr1d' => '5', 'mr2' => '3', 'mr2d' => '3', 'denR' => '15',
            ],
        ])['ok']);

        $this->assertFalse($service->checkSubmission($card, $sum, [
            'step_key' => 'open',
            'values' => ['a4' => '4', 'a6' => '-6', 'a9' => '9', 'seven' => '7'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum, [
            'step_key' => 'open',
            'values' => ['a4' => '4', 'a6' => '6', 'a9' => '9', 'seven' => '7'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $sum, [
            'step_key' => 'combine',
            'values' => ['nx' => '-2', 'n5' => '5'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum, [
            'step_key' => 'combine',
            'values' => ['nx' => '-4', 'n5' => '5'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $sum, [
            'step_key' => 'cross',
            'values' => ['crossL' => '15', 'crossR' => '6', 'prod' => '42'],
        ])['ok']);
        $shift = $service->checkSubmission($card, $sum, ['step_key' => 'shift', 'values' => ['shift' => '-33']]);
        $this->assertTrue($shift['ok']);
        $this->assertFalse($shift['all_ok']);

        $done = $service->checkSubmission($card, $sum, ['step_key' => 'x', 'values' => ['x' => '11/20']]);
        $this->assertTrue($done['all_ok'], json_encode($done));
        $this->assertTrue($service->checkSubmission($card, $sum, ['step_key' => 'x', 'values' => ['x' => '0.55']])['all_ok']);

        $page = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->assertCount(10, $page->answers['play']['items']);
        $this->assertSame('−m', $page->answers['play']['items'][0]['terms'][0]['num']);
        $pageDone = $service->checkSubmission($card, $page, [
            'values' => [
                'q1' => '-5/7',
                'q2' => '36/7',
                'q3' => '5/7',
                'q4' => '11/20',
                'q5' => '-4',
                'q6' => '5/3',
                'q7' => '4/3',
                'q8' => '-17/7',
                'q9' => '2',
                'q10' => '10/11',
            ],
        ]);
        $this->assertTrue($pageDone['all_ok'], json_encode($pageDone));
        $this->assertFalse($service->checkSubmission($card, $page, ['step_key' => 'q1', 'value' => '4'])['ok']);
    }

    public function test_balance_equation_basic_6_cross_multiplication(): void
    {
        $card = MmmCard::query()->where('slug', 'balance-equation-basic-6')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame('Balance the equation — Basic 6', $card->heading);
        $this->assertSame(8, $card->sort_order);
        $this->assertSame(6, $card->exercises()->count());

        $service = app(MmmCardService::class);
        $pure = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $pure, ['step_key' => 'rule', 'value' => 'ok'])['ok']);
        $this->assertFalse($service->checkSubmission($card, $pure, [
            'step_key' => 'products',
            'values' => ['lt' => '3x', 'rb' => '5', 'lb' => '9', 'rt' => '4'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $pure, [
            'step_key' => 'products',
            'values' => ['lt' => '3x', 'rb' => '9', 'lb' => '4', 'rt' => '5'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $pure, [
            'step_key' => 'products',
            'values' => ['lt' => '3x', 'rb' => '9', 'lb' => '4', 'rt' => '4'],
        ])['ok']);
        $done = $service->checkSubmission($card, $pure, ['step_key' => 'x', 'values' => ['x' => '20/27']]);
        $this->assertTrue($done['all_ok'], json_encode($done));

        $bracket = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $bracket, ['step_key' => 'x', 'values' => ['x' => '3/4']])['all_ok']);

        $mixed = $card->exercises()->where('sort_order', 3)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $mixed, ['step_key' => 'join', 'values' => ['top4' => '4x']])['ok']);
        $this->assertFalse($service->checkSubmission($card, $mixed, ['step_key' => 'join', 'values' => ['top4' => '2x']])['ok']);
        $this->assertTrue($service->checkSubmission($card, $mixed, ['step_key' => 'x', 'values' => ['x' => '-1/6']])['all_ok']);

        $ten = $card->exercises()->where('sort_order', 4)->firstOrFail();
        $this->assertCount(10, $ten->answers['play']['items']);
        $tenDone = $service->checkSubmission($card, $ten, [
            'values' => [
                'q1' => '20/27',
                'q2' => '3/4',
                'q3' => '-1/6',
                'q4' => '7/6',
                'q5' => '13',
                'q6' => '13/15',
                'q7' => '1',
                'q8' => '4/9',
                'q9' => '15/2',
                'q10' => '13/8',
            ],
        ]);
        $this->assertTrue($tenDone['all_ok'], json_encode($tenDone));

        $same = $card->exercises()->where('sort_order', 5)->firstOrFail();
        $this->assertTrue($service->checkSubmission($card, $same, [
            'step_key' => 'same',
            'values' => ['t1' => '2', 't2' => '1'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $same, ['step_key' => 'x', 'values' => ['x' => '9/4']])['all_ok']);

        $book = $card->exercises()->where('sort_order', 6)->firstOrFail();
        $this->assertCount(8, $book->answers['play']['items']);
        $bookDone = $service->checkSubmission($card, $book, [
            'values' => [
                'q1' => '1/2',
                'q2' => '1',
                'q3' => '1',
                'q4' => '1',
                'q5' => '2',
                'q6' => '5/3',
                'q7' => '3',
                'q8' => '3',
            ],
        ]);
        $this->assertTrue($bookDone['all_ok'], json_encode($bookDone));
        $this->assertFalse($service->checkSubmission($card, $book, ['step_key' => 'q1', 'value' => '2'])['ok']);
    }

    public function test_bodmas_simple_brackets_solves_inside_then_opens(): void
    {
        $card = MmmCard::query()->where('slug', 'bodmas-simple-brackets')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame('bodmas', $card->kind);
        $this->assertSame(9, $card->sort_order);
        $this->assertSame(4, $card->exercises()->count());

        $service = app(MmmCardService::class);
        $payload = $service->playPayload($card, null, 0);
        $this->assertSame('bodmas', $payload['exercise']['play_kind']);
        $this->assertArrayNotHasKey('check', $payload['exercise']['bodmas']);

        $bracket = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $this->assertFalse($service->checkSubmission($card, $bracket, [
            'step_key' => 'tap',
            'values' => ['hits' => ['two']],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $bracket, [
            'step_key' => 'tap',
            'values' => ['hits' => ['br']],
        ])['ok']);
        $inside = $service->checkSubmission($card, $bracket, [
            'step_key' => 'inside',
            'values' => ['in' => '15'],
        ]);
        $this->assertTrue($inside['ok']);
        $this->assertFalse($inside['all_ok']);
        $this->assertTrue($service->checkSubmission($card, $bracket, [
            'step_key' => 'open',
            'value' => 'ok',
        ])['ok']);
        $done = $service->checkSubmission($card, $bracket, [
            'step_key' => 'times',
            'values' => ['ans' => '30'],
        ]);
        $this->assertTrue($done['all_ok'], json_encode($done));

        $plain = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->assertFalse($service->checkSubmission($card, $plain, [
            'step_key' => 'divisions',
            'values' => ['hits' => ['d1']],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $plain, [
            'step_key' => 'divisions',
            'values' => ['hits' => ['d1', 'd2', 'seven']],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $plain, [
            'step_key' => 'divisions',
            'values' => ['hits' => ['d2', 'd1']],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $plain, [
            'step_key' => 'quotients',
            'values' => ['left' => '5', 'right' => '3'],
        ])['ok']);
        $this->assertFalse($service->checkSubmission($card, $plain, [
            'step_key' => 'last',
            'values' => ['ans' => '37'],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $plain, [
            'step_key' => 'last',
            'values' => ['ans' => '34'],
        ])['all_ok']);

        $mixed = $card->exercises()->where('sort_order', 3)->firstOrFail();
        $this->assertFalse($service->checkSubmission($card, $mixed, [
            'step_key' => 'bracket',
            'values' => ['hits' => ['d1']],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $mixed, [
            'step_key' => 'bracket',
            'values' => ['hits' => ['br']],
        ])['ok']);
        $this->assertTrue($service->checkSubmission($card, $mixed, [
            'step_key' => 'last',
            'values' => ['ans' => '42'],
        ])['all_ok']);

        $page = $card->exercises()->where('sort_order', 4)->firstOrFail();
        $this->assertSame('fill_page', $service->exercisePayload($page)['play_kind']);
        $pageDone = $service->checkSubmission($card, $page, [
            'values' => [
                'q1' => '24',
                'q2' => '11',
                'q3' => '4',
                'q4' => '30',
                'q5' => '37',
                'q6' => '4',
                'q7' => '7',
                'q8' => '7',
            ],
        ]);
        $this->assertTrue($pageDone['all_ok'], json_encode($pageDone));
        $this->assertFalse($service->checkSubmission($card, $page, ['step_key' => 'q1', 'value' => '12'])['ok']);
    }

    public function test_congruence_sas_card_checks_order_marks_table_and_case(): void
    {
        $card = MmmCard::query()->where('slug', 'congruence-sas')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $service = app(MmmCardService::class);

        $this->assertSame('congruence', $service->exercisePayload($exercise)['play_kind']);

        $wrongOrder = $service->checkSubmission($card, $exercise, [
            'step_key' => 'order',
            'values' => ['letters' => ['P', 'R', 'Q'], 'sides' => ['AB' => '5', 'AC' => '6']],
        ]);
        $this->assertFalse($wrongOrder['ok']);

        $wrongSides = $service->checkSubmission($card, $exercise, [
            'step_key' => 'order',
            'values' => ['letters' => ['P', 'Q', 'R'], 'sides' => ['AB' => '6', 'AC' => '5']],
        ]);
        $this->assertFalse($wrongSides['ok']);

        $order = $service->checkSubmission($card, $exercise, [
            'step_key' => 'order',
            'values' => ['letters' => ['P', 'Q', 'R'], 'sides' => ['AB' => '5', 'AC' => '6']],
        ]);
        $this->assertTrue($order['ok'], $order['message']);

        $marks = $service->checkSubmission($card, $exercise, [
            'step_key' => 'marks',
            'values' => ['placed' => [
                'AB' => '5',
                'PQ' => '5',
                'AC' => '6',
                'PR' => '6',
                'A' => 'angle',
                'P' => 'angle',
            ]],
        ]);
        $this->assertTrue($marks['ok'], json_encode($marks['cell_results']));

        $table = $service->checkSubmission($card, $exercise, [
            'step_key' => 'table',
            'values' => ['rows' => [
                ['parts' => 'AB = PQ', 'reason' => 'Given equal sides of 5 cm', 'type' => 'Side'],
                ['parts' => 'AC = PR', 'reason' => 'Given equal sides of 6 cm', 'type' => 'Side'],
                ['parts' => '∠BAC = ∠QPR', 'reason' => 'Given equal angles', 'type' => 'Angle'],
            ]],
        ]);
        $this->assertTrue($table['ok'], json_encode($table['cell_results']));

        $sss = $service->checkSubmission($card, $exercise, [
            'step_key' => 'case',
            'values' => ['case' => 'SSS'],
        ]);
        $this->assertFalse($sss['ok']);
        $this->assertStringContainsString('angle', strtolower($sss['message']));

        $sas = $service->checkSubmission($card, $exercise, [
            'step_key' => 'case',
            'values' => ['case' => 'SAS'],
        ]);
        $this->assertTrue($sas['all_ok'], $sas['message']);
        $this->assertSame('SAS', $sas['summary']['case'] ?? null);
        $this->assertCount(3, $sas['summary']['rows'] ?? []);
    }

    public function test_congruence_sss_needs_the_triangle_turned_to_qpr(): void
    {
        $card = MmmCard::query()->where('slug', 'congruence-sas')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $service = app(MmmCardService::class);

        $copied = $service->checkSubmission($card, $exercise, [
            'step_key' => 'order',
            'values' => ['letters' => ['P', 'Q', 'R'], 'sides' => ['AB' => '5', 'BC' => '6', 'AC' => '7']],
        ]);
        $this->assertFalse($copied['ok']);

        $turned = $service->checkSubmission($card, $exercise, [
            'step_key' => 'order',
            'values' => ['letters' => ['Q', 'P', 'R'], 'sides' => ['AB' => '5', 'BC' => '6', 'AC' => '7']],
        ]);
        $this->assertTrue($turned['ok'], $turned['message']);

        $proved = $service->checkSubmission($card, $exercise, [
            'step_key' => 'case',
            'values' => ['case' => 'SSS'],
        ]);
        $this->assertTrue($proved['all_ok'], $proved['message']);
        $this->assertSame('△ABC ≅ △QPR', $proved['summary']['statement'] ?? null);
        $this->assertSame('SSS', $proved['summary']['case'] ?? null);
    }

    private function marksVariableCountExercise(MmmCard $card): \App\Models\MmmCardExercise
    {
        return $card->exercises()->create([
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
                    'count' => ['20-x', '20 - x', '20-X'],
                    'total' => ['-20+x', 'x-20', '-(20-x)', '-1*(20-x)', '-1(20-x)', '(-1)*(20-x)'],
                ],
                'grand_total' => '25',
                'x_value' => '15',
            ],
        ]);
    }
}
