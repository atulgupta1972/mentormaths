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

    public function test_admin_can_try_pack_as_student_and_answer(): void
    {
        $this->withoutVite();

        $cards = AlgebraCardPack::playableCards(AlgebraCardPack::FRACTION_SIMPLIFICATION);
        if ($cards === []) {
            $this->markTestSkipped('Playable algebra cards are not present.');
        }

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $pack = AlgebraCardPack::FRACTION_SIMPLIFICATION;

        $this->actingAs($admin)
            ->get(route('admin.algebra-cards.try', ['pack' => $pack, 'restart' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/AlgebraCards/Try')
                ->where('play.status', 'in_progress')
                ->where('play.phase', 'teach')
                ->where('play.total', count($cards))
                ->has('play.card.diagram_url')
            );

        $this->actingAs($admin)
            ->get(route('admin.algebra-cards.try', ['pack' => $pack, 'to_quiz' => 1]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('play.phase', 'quiz')
                ->has('play.card.options')
            );

        $correct = (int) $cards[0]['correct_index'];

        $this->actingAs($admin)
            ->post(route('admin.algebra-cards.try-answer', ['pack' => $pack]), [
                'option_index' => $correct,
            ])
            ->assertRedirect(route('admin.algebra-cards.try', ['pack' => $pack]));

        $this->actingAs($admin)
            ->get(route('admin.algebra-cards.try', ['pack' => $pack]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('play.score', 1)
                ->where('play.feedback.correct', true)
            );
    }
}
