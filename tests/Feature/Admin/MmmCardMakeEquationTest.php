<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardMakeEquationTest extends TestCase
{
    use RefreshDatabase;

    public function test_making_an_equation_accepts_the_x_amount_then_the_statement(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'making-an-equation')->firstOrFail();
        $this->assertSame(MmmCard::KIND_MAKE_EQUATION, $card->kind);
        $this->assertCount(7, $card->exercises);

        $demo = $card->exercises()->where('is_demo', true)->firstOrFail();
        $service = app(MmmCardService::class);
        $payload = $service->exercisePayload($demo);
        $this->assertSame('make_equation', $payload['play_kind']);
        $this->assertSame('more than', $payload['make_equation']['phrase']);
        $this->assertArrayNotHasKey('define', $payload['make_equation']);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demo->id,
                'step_key' => 'define',
                'value' => '5+x',
                'values' => ['define' => '5+x'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('all_ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demo->id,
                'step_key' => 'define',
                'value' => '2 + x',
                'values' => ['define' => '2 + x'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('all_ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demo->id,
                'step_key' => 'equation',
                'value' => 'x=4',
                'values' => ['define' => 'x+2', 'equation' => 'x=4'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demo->id,
                'step_key' => 'equation',
                'value' => '2x+2=10',
                'values' => ['define' => 'x+2', 'equation' => '2x+2=10'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('all_ok', true);

        $half = $card->exercises()->where('sort_order', 4)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $half->id,
                'step_key' => 'define',
                'value' => '(1/2)x',
                'values' => ['define' => '(1/2)x'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $half->id,
                'step_key' => 'equation',
                'value' => 'x + x/2 = 18',
                'values' => ['define' => 'x/2', 'equation' => 'x + x/2 = 18'],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', $card))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MmmCards/Play')
                ->where('exercise.play_kind', 'make_equation')
                ->where('exercise.make_equation.number_line', 'My friend has 5 + 2 = 7')
                ->where('card.heading', 'Making an equation — say it with x'));
    }
};
