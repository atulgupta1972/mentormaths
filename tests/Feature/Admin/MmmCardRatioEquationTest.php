<?php

namespace Tests\Feature\Admin;

use App\Models\MmmCard;
use App\Models\User;
use App\Services\MmmCardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MmmCardRatioEquationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ratio_equation_uses_x_only_as_the_common_factor(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'ratios-equation')->firstOrFail();
        $basics = MmmCard::query()->where('slug', 'ratios-basics')->firstOrFail();

        $this->assertSame(MmmCard::KIND_RATIO_EQUATION, $card->kind);
        $this->assertSame('Ratios', $card->sub_heading);
        $this->assertSame('Ratios — ratio equation', $card->heading);
        $this->assertSame($basics->chapter_head_id, $card->chapter_head_id);
        $this->assertCount(6, $card->exercises);

        $boys = $card->exercises()->where('sort_order', 1)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($boys);
        $this->assertSame('ratio_equation', $payload['play_kind']);
        $this->assertArrayNotHasKey('check', $payload['ratio_equation']);
        $this->assertSame(2, $payload['ratio_equation']['rows'][0]['part']);

        $check = function (string $key, string $value, array $values, bool $expect) use ($admin, $card, $boys) {
            $response = $this->actingAs($admin)
                ->postJson(route('admin.mmm-cards.check', $card), [
                    'exercise_id' => $boys->id,
                    'step_key' => $key,
                    'value' => $value,
                    'values' => $values,
                ])
                ->assertOk();
            $this->assertSame($expect, $response->json('ok'), $key.' ['.$value.'] '.$response->json('message'));

            return $response;
        };

        $check('first', '2', ['first' => '2'], false);
        $check('first', 'x', ['first' => 'x'], false);
        $check('first', '20', ['first' => '20'], false);
        $check('first', '2x', ['first' => '2x'], true);
        $check('second', '3*x', ['second' => '3*x'], true);

        $check('equation', 'x=10', ['equation' => 'x=10'], false);
        $check('equation', '2x+3x=50', ['equation' => '2x+3x=50'], true);
        $check('equation', '5x=50', ['equation' => '5x=50'], true);

        $check('factor', '20', ['factor' => '20'], false);
        $check('factor', '2*10', ['factor' => '2*10'], false);
        $check('factor', '10', ['factor' => '10'], true);

        $check('first_value', '10', ['first_value' => '10'], false);
        $check('first_value', '2x', ['first_value' => '2x'], false);
        $check('first_value', '2*10', ['first_value' => '2*10'], true);
        $check('second_value', '3 × 10', ['second_value' => '3 × 10'], true);

        $done = $check('second_value', '30', [
            'first' => '2x',
            'second' => '3x',
            'equation' => '2x+3x=50',
            'factor' => '10',
            'first_value' => '20',
            'second_value' => '30',
        ], true);
        $this->assertTrue($done->json('all_ok'));

        $this->actingAs($admin)
            ->get(route('admin.mmm-cards.play', $card))
            ->assertOk()
            ->assertInertia(fn ($inertia) => $inertia
                ->component('Student/MmmCards/Play')
                ->where('exercise.play_kind', 'ratio_equation')
                ->where('card.heading', 'Ratios — ratio equation'));
    }

    public function test_the_last_page_asks_for_each_quantity_after_the_units_match(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $card = MmmCard::query()->where('slug', 'ratios-equation')->firstOrFail();
        $page = $card->exercises()->where('sort_order', 6)->firstOrFail();
        $payload = app(MmmCardService::class)->exercisePayload($page);

        $this->assertSame('ratio_quantities', $payload['play_kind']);
        $this->assertCount(10, $payload['ratio_quantities']['items']);
        $this->assertArrayNotHasKey('check', $payload['ratio_quantities']);
        $this->assertArrayNotHasKey('value', $payload['ratio_quantities']['items'][1]['quantities'][0]);

        $check = function (string $key, string $value, bool $expect) use ($admin, $card, $page) {
            $response = $this->actingAs($admin)
                ->postJson(route('admin.mmm-cards.check', $card), [
                    'exercise_id' => $page->id,
                    'step_key' => $key,
                    'value' => $value,
                    'values' => [$key => $value],
                ])
                ->assertOk();
            $this->assertSame($expect, $response->json('ok'), $key.' ['.$value.'] '.$response->json('message'));
        };

        $check('s2.first', '2', false);
        $check('s2.first', '2 kg', false);
        $check('s2.first', '1000', false);
        $check('s2.first', '4000', true);
        $check('s2.second', '500', false);
        $check('s2.second', '1000 g', true);

        $values = [
            's1.first' => '15',
            's1.second' => '25',
            's2.first' => '4000',
            's2.second' => '1000',
            's3.first' => '360',
            's3.second' => '90',
            's4.first' => '180',
            's4.second' => '60',
            's5.first' => '400',
            's5.second' => '160',
            's6.first' => '500',
            's6.second' => '200',
            's7.first' => '90',
            's7.second' => '120',
            's8.first' => '1500',
            's8.second' => '2000',
            's9.first' => '6000',
            's9.second' => '1500',
            's10.first' => '4000',
            's10.second' => '1600',
        ];
        $this->actingAs($admin)
            ->postJson(route('admin.mmm-cards.check', $card), [
                'exercise_id' => $page->id,
                'step_key' => 's10.second',
                'value' => '1600',
                'values' => $values,
            ])
            ->assertOk()
            ->assertJsonPath('all_ok', true);
    }
}
