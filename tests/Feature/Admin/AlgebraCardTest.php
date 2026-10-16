<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Support\AlgebraCardPack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AlgebraCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_open_algebra_card_hub(): void
    {
        $this->withoutVite();

        if (AlgebraCardPack::options() === []) {
            $this->markTestSkipped('Algebra card sample diagrams are not present.');
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.algebra-cards.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AlgebraCards/Index')
                ->has('packs')
                ->has('cards')
                ->where('selectedPack.key', AlgebraCardPack::FRACTION_SIMPLIFICATION)
            );
    }

    public function test_admin_can_view_algebra_card_figure(): void
    {
        $this->withoutVite();

        $path = AlgebraCardPack::figurePath(AlgebraCardPack::FRACTION_SIMPLIFICATION, 'q1.png');
        if ($path === null) {
            $this->markTestSkipped('q1.png for fraction simplification cards is not present.');
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get(route('admin.algebra-cards.figure', [
                'pack' => AlgebraCardPack::FRACTION_SIMPLIFICATION,
                'figure' => 'q1.png',
            ]))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
    }
}
