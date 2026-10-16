<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\AlgebraCardPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AlgebraCardController extends Controller
{
    private const PREVIEW_SESSION_KEY = 'algebra_cards_admin_try';

    public function index(Request $request): Response
    {
        $packs = AlgebraCardPack::options();
        $selectedKey = (string) $request->query('pack', $packs[0]['key'] ?? '');
        $selected = AlgebraCardPack::find($selectedKey) ?? ($packs[0] ?? null);

        return Inertia::render('Admin/AlgebraCards/Index', [
            'packs' => $packs,
            'selectedPack' => $selected,
            'cards' => $selected ? AlgebraCardPack::cardsFor($selected['key']) : [],
        ]);
    }

    public function figure(string $pack, string $figure): BinaryFileResponse
    {
        $path = AlgebraCardPack::figurePath($pack, $figure);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function tryPack(Request $request, string $pack): Response|RedirectResponse
    {
        $meta = AlgebraCardPack::find($pack);
        if ($meta === null) {
            return redirect()
                ->route('admin.algebra-cards.index')
                ->with('error', 'That algebra card pack was not found.');
        }

        $cards = AlgebraCardPack::playableCards($pack);
        if ($cards === []) {
            return redirect()
                ->route('admin.algebra-cards.index', ['pack' => $pack])
                ->with('error', 'No playable cards in this pack yet.');
        }

        if ($request->boolean('restart') || ! $this->sessionMatches($request, $pack, count($cards))) {
            $state = [
                'pack' => $pack,
                'index' => 0,
                'score' => 0,
                'answered' => 0,
                'total' => count($cards),
                'last_feedback' => null,
            ];
            $request->session()->put(self::PREVIEW_SESSION_KEY, $state);
        } else {
            $state = $request->session()->get(self::PREVIEW_SESSION_KEY);
            if ($request->boolean('continue') && is_array($state)) {
                $state['last_feedback'] = null;
                $request->session()->put(self::PREVIEW_SESSION_KEY, $state);
            }
        }

        return $this->renderTry($meta, $cards, is_array($state) ? $state : [
            'pack' => $pack,
            'index' => 0,
            'score' => 0,
            'answered' => 0,
            'total' => count($cards),
            'last_feedback' => null,
        ]);
    }

    public function tryAnswer(Request $request, string $pack): RedirectResponse
    {
        $validated = $request->validate([
            'option_index' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $cards = AlgebraCardPack::playableCards($pack);
        $state = $request->session()->get(self::PREVIEW_SESSION_KEY);

        if (! is_array($state) || ($state['pack'] ?? null) !== $pack || $cards === []) {
            return redirect()
                ->route('admin.algebra-cards.try', ['pack' => $pack, 'restart' => 1]);
        }

        $index = (int) ($state['index'] ?? 0);
        if ($index >= count($cards)) {
            return redirect()->route('admin.algebra-cards.try', ['pack' => $pack]);
        }

        $card = $cards[$index];
        $chosen = (int) $validated['option_index'];
        $correct = $chosen === (int) $card['correct_index'];

        $state['answered'] = (int) ($state['answered'] ?? 0) + 1;
        if ($correct) {
            $state['score'] = (int) ($state['score'] ?? 0) + 1;
        }
        $state['last_feedback'] = [
            'correct' => $correct,
            'chosen_index' => $chosen,
            'correct_index' => (int) $card['correct_index'],
            'correct_option' => $card['options'][(int) $card['correct_index']] ?? '',
            'explanation' => $card['explanation'],
            'method_hint' => $card['method_hint'],
            'card_id' => $card['id'],
        ];
        $state['index'] = $index + 1;
        $request->session()->put(self::PREVIEW_SESSION_KEY, $state);

        return redirect()->route('admin.algebra-cards.try', ['pack' => $pack]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>  $state
     */
    private function renderTry(array $meta, array $cards, array $state): Response
    {
        $index = (int) ($state['index'] ?? 0);
        $done = $index >= count($cards);
        $current = $done ? null : $cards[$index];
        $feedback = $state['last_feedback'] ?? null;

        // After answering, show feedback against the card just completed.
        $feedbackCard = null;
        if (is_array($feedback) && isset($feedback['card_id'])) {
            $feedbackCard = collect($cards)->firstWhere('id', (int) $feedback['card_id']);
        }

        return Inertia::render('Admin/AlgebraCards/Try', [
            'pack' => [
                'key' => $meta['key'],
                'label' => $meta['label'],
                'level' => $meta['level'],
            ],
            'play' => [
                'status' => $done ? 'completed' : 'in_progress',
                'index' => min($index + 1, count($cards)),
                'total' => count($cards),
                'score' => (int) ($state['score'] ?? 0),
                'answered' => (int) ($state['answered'] ?? 0),
                'card' => $current ? [
                    'id' => $current['id'],
                    'title' => $current['title'],
                    'question' => $current['question'],
                    'options' => $current['options'],
                    'diagram_url' => $current['diagram_url'],
                    'method_hint' => $current['method_hint'],
                ] : null,
                'feedback' => $feedback,
                'feedback_card' => $feedbackCard ? [
                    'id' => $feedbackCard['id'],
                    'title' => $feedbackCard['title'],
                    'diagram_url' => $feedbackCard['diagram_url'],
                ] : null,
            ],
            'back_url' => route('admin.algebra-cards.index', ['pack' => $meta['key']]),
            'restart_url' => route('admin.algebra-cards.try', ['pack' => $meta['key'], 'restart' => 1]),
        ]);
    }

    private function sessionMatches(Request $request, string $pack, int $total): bool
    {
        $state = $request->session()->get(self::PREVIEW_SESSION_KEY);

        return is_array($state)
            && ($state['pack'] ?? null) === $pack
            && (int) ($state['total'] ?? 0) === $total;
    }
}
