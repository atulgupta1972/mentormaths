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

    public function test_marks_scoring_demo_is_seeded_published(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->first();

        $this->assertNotNull($card);
        $this->assertTrue($card->isPublished());
        $this->assertSame(11, $card->exercises()->count());
        $this->assertTrue((bool) $card->exercises()->orderBy('sort_order')->first()?->is_demo);
    }

    public function test_variable_twist_correct_marks_as_x(): void
    {
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 8)->firstOrFail();
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
        $exercise = $card->exercises()->where('sort_order', 9)->firstOrFail();
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
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();
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
        $exercise = $card->exercises()->where('sort_order', 5)->firstOrFail();
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
}
