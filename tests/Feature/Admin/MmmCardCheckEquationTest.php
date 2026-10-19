<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardCheckEquationTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_check_accepts_spaced_equation_with_plus_signs(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $exercise->id,
                'rows' => [
                    'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                    'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => '-20+x'],
                ],
                'grand_total' => '25',
                'equation' => '2x+(-20+x)=25',
                'x_value' => '15',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('cell_results.equation', true);
    }

    public function test_form_urlencoded_plus_signs_are_destroyed_but_json_is_used_by_ui(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'marks-scoring-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 7)->firstOrFail();

        // Simulates browsers turning + into space when body is form-urlencoded.
        $response = $this->actingAs($admin)
            ->post(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $exercise->id,
                'rows' => [
                    'correct' => ['marks' => '2', 'count' => 'x', 'total' => '2x'],
                    'wrong' => ['marks' => '-1', 'count' => '20-x', 'total' => '-20+x'],
                ],
                'grand_total' => '25',
                'equation' => '2x (-20 x)=25',
                'x_value' => '15',
            ], ['Accept' => 'application/json']);

        $response->assertOk();
        $this->assertFalse($response->json('ok'));
        $this->assertFalse($response->json('cell_results.equation'));
    }
}
