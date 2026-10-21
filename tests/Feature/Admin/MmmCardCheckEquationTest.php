<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardCheckEquationTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_check_passes_when_table_and_x_are_correct(): void
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
                'x_value' => '15',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonMissingPath('cell_results.equation');
    }

    public function test_admin_can_preview_a_specific_exercise(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'my-money-table')->firstOrFail();
        $exercise = $card->exercises()->where('sort_order', 12)->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', ['mmmCard' => $card, 'exercise' => 12]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MmmCards/Play')
                ->where('exercise.id', $exercise->id)
                ->where('exercise.sort_order', 12)
                ->where('is_staff_preview', true));
    }
}
