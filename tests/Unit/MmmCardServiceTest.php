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
        $this->assertSame(11, $card->exercises()->count());
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
                'rs2' => ['denomination' => '5', 'count' => '8', 'total' => '16'],
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

    public function test_my_money_condition_sum_teaches_equation(): void
    {
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 10)->firstOrFail();
        $service = app(MmmCardService::class);

        $result = $service->checkSubmission($card, $exercise, [
            'rows' => [
                'rs2' => ['denomination' => '2', 'count_x' => 'x+5', 'count' => '10', 'total' => '20'],
                'rs5' => ['denomination' => '5', 'count_x' => 'x', 'count' => '5', 'total' => '25'],
                'rs10' => ['denomination' => '10', 'count_x' => '2x', 'count' => '10', 'total' => '100'],
            ],
            'grand_count' => '25',
            'grand_total' => '145',
            'equation' => '4x+5=25',
            'x_value' => '5',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['cell_results']));
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
