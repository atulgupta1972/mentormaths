<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardAgeTableTest extends TestCase
{
    use RefreshDatabase;

    public function test_age_table_part_follows_the_first_equation_card(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $part1 = MmmCard::query()->where('slug', 'making-an-equation')->firstOrFail();
        $card = MmmCard::query()->where('slug', 'making-an-equation-age')->firstOrFail();

        $this->assertSame(MmmCard::KIND_AGE_TABLE, $card->kind);
        $this->assertSame('Making an equation', $card->sub_heading);
        $this->assertCount(7, $card->exercises);
        $this->assertSame($card->id, app(MmmCardService::class)->nextPartCard($part1)?->id);

        $words = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $words->id,
                'step_key' => 'ago.age',
                'value' => 'x-5',
                'values' => [
                    'now.age' => 'x',
                    'after.age' => '5+x',
                    'ago.age' => 'x-5',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $times = $card->exercises()->where('sort_order', 2)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($times);
        $this->assertSame('age_table', $payload['play_kind']);
        $this->assertArrayNotHasKey('check', $payload['age_table']);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $times->id,
                'step_key' => 'sanjay.later',
                'value' => 'x+2',
                'values' => ['sanjay.later' => 'x+2'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $times->id,
                'step_key' => 'equation',
                'value' => '3x+10=40',
                'values' => [
                    'sanjay.now' => 'x',
                    'sanjay.later' => 'x+5',
                    'rakesh.now' => '2x',
                    'rakesh.later' => '2x+5',
                    'equation' => '3x+10=40',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);

        $half = $card->exercises()->where('sort_order', 5)->firstOrFail();
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $half->id,
                'step_key' => 'meera.ago',
                'value' => '(1/2)x-6',
                'values' => ['meera.ago' => '(1/2)x-6'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', ['mmmCard' => $card, 'exercise' => 2]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Student/MmmCards/Play')
                ->where('exercise.play_kind', 'age_table')
                ->where('exercise.age_table.rows.1.name', 'Rakesh')
                ->where('card.heading', 'Making an equation — age problems'));
    }

    public function test_the_page_after_the_tables_asks_only_for_present_ages(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'making-an-equation-age')->firstOrFail();
        $page = $card->exercises()->where('sort_order', 7)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($page);

        $this->assertSame('age_answers', $payload['play_kind']);
        $this->assertCount(10, $payload['age_answers']['items']);
        $this->assertSame(
            ['Name', 'Current age', 'Age after 5 years'],
            $payload['age_answers']['items'][0]['columns'],
        );
        $this->assertArrayNotHasKey('age', $payload['age_answers']['items'][0]['people'][0]);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $page->id,
                'step_key' => 's1.sanjay',
                'value' => '15',
                'values' => ['s1.sanjay' => '15'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', false);

        $values = [];
        foreach ($page->answers['check'] as $key => $age) {
            $values[$key] = (string) $age;
        }
        $values['s1.rakesh'] = '20';

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $page->id,
                'step_key' => 's1.sanjay',
                'value' => '10',
                'values' => ['s1.sanjay' => '10', 's1.rakesh' => '20'],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('all_ok', false);

        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $page->id,
                'step_key' => 's10.grandfather',
                'value' => '50',
                'values' => $values,
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);
    }
}
