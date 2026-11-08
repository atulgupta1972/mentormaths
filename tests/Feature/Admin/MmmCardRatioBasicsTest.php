<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardRatioBasicsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ratio_basics_asks_for_the_simplest_form_in_the_same_unit(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'ratios-basics')->firstOrFail();

        $this->assertSame(MmmCard::KIND_RATIO, $card->kind);
        $this->assertSame('Ratios', $card->sub_heading);
        $this->assertSame('Ratio & Proportion', $card->chapterHead?->name);
        $this->assertCount(10, $card->exercises);

        $boys = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($boys);
        $this->assertSame('ratio', $payload['play_kind']);
        $this->assertArrayNotHasKey('check', $payload['ratio']);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $boys->id,
                'step_key' => 'ratio',
                'value' => '12:18',
                'values' => ['ratio' => '12:18'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $boys->id,
                'step_key' => 'ratio',
                'value' => '18 to 12',
                'values' => ['ratio' => '18 to 12'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $boys->id,
                'step_key' => 'ratio',
                'value' => '2/3',
                'values' => ['ratio' => '2/3'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('all_ok', true);

        $grams = $card->exercises()->where('sort_order', 6)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $grams->id,
                'step_key' => 'ratio',
                'value' => '1:300',
                'values' => ['ratio' => '1:300'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $grams->id,
                'step_key' => 'ratio',
                'value' => '10 : 3',
                'values' => ['ratio' => '10 : 3'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $page = $card->exercises()->where('sort_order', 10)->firstOrFail();
        $pagePayload = app(MmmCardService::class)->exercisePayload($page);
        $this->assertCount(10, $pagePayload['ratio']['items']);
        $this->assertArrayNotHasKey('ratio', $pagePayload['ratio']['items'][5]);

        $values = [];
        foreach ($page->answers['check']['items'] as $key => $ratio) {
            $values[$key] = $ratio;
        }
        $values['s6'] = '2:500';

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $page->id,
                'step_key' => 's6',
                'value' => '2:500',
                'values' => ['s6' => '2:500'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $values['s6'] = '4:1';
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $page->id,
                'step_key' => 's10',
                'value' => '5:1',
                'values' => $values,
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', $card))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->component('Student/MmmCards/Play')
                ->where('exercise.play_kind', 'ratio')
                ->where('card.heading', 'Ratios — basics'));
    }
}
