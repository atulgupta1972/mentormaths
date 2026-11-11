<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardSimpleInterestPracticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_practice_card_follows_guided_si_card_and_checks_final_answer(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $guided = MmmCard::query()->where('slug', 'banking-simple-interest')->firstOrFail();
        $card = MmmCard::query()->where('slug', 'banking-simple-interest-practice')->firstOrFail();

        $this->assertSame(MmmCard::KIND_SIMPLE_INTEREST, $card->kind);
        $this->assertSame('Simple Interest', $card->sub_heading);
        $this->assertCount(10, $card->exercises);
        $this->assertSame($card->id, app(MmmCardService::class)->nextPartCard($guided)?->id);

        $first = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($first);
        $this->assertSame('simple_interest_practice', $payload['play_kind']);
        $this->assertArrayNotHasKey('check', $payload['simple_interest_practice']);
        $this->assertSame('SI = PRT/100', $payload['simple_interest_practice']['formula_outline']);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $first->id,
                'step_key' => 'answer',
                'value' => '2400',
                'values' => ['answer' => '2400'],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $amount = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $amount->id,
                'step_key' => 'answer',
                'value' => '5808',
                'values' => ['answer' => '₹5808'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $principal = $card->exercises()->where('sort_order', 5)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $principal->id,
                'step_key' => 'answer',
                'value' => '3500',
                'values' => ['answer' => '3500'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', ['mmmCard' => $card, 'exercise' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MmmCards/Play')
                ->where('exercise.play_kind', 'simple_interest_practice')
                ->where('card.heading', 'Banking — Simple Interest — practice sums'));
    }
}
