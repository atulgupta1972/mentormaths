<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardSimpleInterestTest extends TestCase
{
    use RefreshDatabase;

    public function test_banking_simple_interest_card_uses_notebook_steps(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'banking-simple-interest')->firstOrFail();

        $this->assertSame(MmmCard::KIND_SIMPLE_INTEREST, $card->kind);
        $this->assertSame('Simple Interest', $card->sub_heading);
        $this->assertCount(10, $card->exercises);
        $this->assertSame('Banking', $card->chapterHead?->name);

        $demoSi = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($demoSi);
        $this->assertSame('simple_interest', $payload['play_kind']);
        $this->assertArrayNotHasKey('check', $payload['simple_interest']);
        $this->assertCount(3, $payload['simple_interest']['given_rows']);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demoSi->id,
                'step_key' => 'given.R',
                'value' => '5%',
                'values' => ['given.R' => '5%'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demoSi->id,
                'step_key' => 'formula',
                'value' => 'SI = PRT/100',
                'values' => [
                    'given.P' => '1000',
                    'given.R' => '5',
                    'given.T' => '2',
                    'to_find' => 'SI',
                    'formula' => 'SI = PRT/100',
                    'step.SI' => '100',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $demoAmount = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $demoAmount->id,
                'step_key' => 'step.A',
                'value' => '115',
                'values' => [
                    'given.P' => '100',
                    'given.R' => '5',
                    'given.T' => '3 years',
                    'to_find' => 'Amount',
                    'formula' => 'A = P + SI',
                    'step.SI' => '15',
                    'step.A' => '115',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $findRate = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $findRate->id,
                'step_key' => 'step.R',
                'value' => '5',
                'values' => [
                    'given.P' => '5000',
                    'given.SI' => '750',
                    'given.T' => '3',
                    'to_find' => 'R',
                    'formula' => 'R = (SI × 100)/(P × T)',
                    'step.R' => '5',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', ['mmmCard' => $card, 'exercise' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MmmCards/Play')
                ->where('exercise.play_kind', 'simple_interest')
                ->where('card.heading', 'Banking — Simple Interest'));
    }
}
