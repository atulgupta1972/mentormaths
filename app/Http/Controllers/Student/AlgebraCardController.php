<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\StudentSeriesActivityDay;
use App\Services\BasicsDrillSessionService;
use App\Services\SeriesActivityService;
use App\Support\AlgebraCardPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AlgebraCardController extends Controller
{
    private const SESSION_KEY = 'student_algebra_cards_play';

    public function __construct(
        private SeriesActivityService $series,
        private BasicsDrillSessionService $basics,
    ) {}

    public function entry(Request $request): RedirectResponse
    {
        $student = $request->user()?->student;
        if (! $student) {
            return redirect()->route('dashboard');
        }

        return redirect()->route($this->series->showRouteName($student));
    }

    public function show(Request $request): Response|RedirectResponse
    {
        $student = $request->user()?->student;
        if (! $student) {
            return redirect()->route('dashboard')->with('error', 'Student profile required.');
        }

        if ($this->series->todaysKind($student) !== StudentSeriesActivityDay::KIND_ALGEBRA) {
            return redirect()->route($this->series->showRouteName($student));
        }

        $packKey = $this->series->algebraPackKey();
        $meta = AlgebraCardPack::find($packKey);
        $cards = AlgebraCardPack::playableCards($packKey, 'student.algebra-cards.figure');

        if ($meta === null || $cards === []) {
            return redirect()->route('dashboard')->with('error', 'Algebra cards are not available yet.');
        }

        if ($this->series->algebraCompletedToday($student) && ! $request->boolean('restart')) {
            return $this->renderCompleted($meta, $cards, $student, $request);
        }

        if ($request->boolean('restart') || ! $this->sessionMatches($request, $packKey, count($cards))) {
            $state = $this->freshState($packKey, count($cards));
            $request->session()->put(self::SESSION_KEY, $state);
        } else {
            $state = $request->session()->get(self::SESSION_KEY);
            if (! is_array($state)) {
                $state = $this->freshState($packKey, count($cards));
                $request->session()->put(self::SESSION_KEY, $state);
            }

            if ($request->boolean('to_quiz') && ($state['phase'] ?? '') === 'teach') {
                $state['phase'] = 'quiz';
                $state['last_feedback'] = null;
                $request->session()->put(self::SESSION_KEY, $state);
            }

            if ($request->boolean('to_teach') && ($state['phase'] ?? '') === 'quiz') {
                $state['phase'] = 'teach';
                $state['last_feedback'] = null;
                $request->session()->put(self::SESSION_KEY, $state);
            }

            if ($request->boolean('continue')) {
                $state['last_feedback'] = null;
                $state['phase'] = 'teach';
                $request->session()->put(self::SESSION_KEY, $state);
            }
        }

        $index = (int) ($state['index'] ?? 0);
        if ($index >= count($cards)) {
            $this->series->markAlgebraComplete($student, $packKey);
            $request->session()->forget(self::SESSION_KEY);

            return $this->renderCompleted($meta, $cards, $student, $request, $state);
        }

        return $this->renderPlay($meta, $cards, $state, $student);
    }

    public function answer(Request $request): RedirectResponse
    {
        $student = $request->user()?->student;
        if (! $student) {
            return redirect()->route('dashboard');
        }

        if ($this->series->todaysKind($student) !== StudentSeriesActivityDay::KIND_ALGEBRA) {
            return redirect()->route($this->series->showRouteName($student));
        }

        $validated = $request->validate([
            'option_index' => ['required', 'integer', 'min:0', 'max:20'],
        ]);

        $packKey = $this->series->algebraPackKey();
        $cards = AlgebraCardPack::playableCards($packKey, 'student.algebra-cards.figure');
        $state = $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ($state['pack'] ?? null) !== $packKey || $cards === []) {
            return redirect()->route('student.algebra-cards.show', ['restart' => 1]);
        }

        if (($state['phase'] ?? '') !== 'quiz') {
            return redirect()->route('student.algebra-cards.show', ['to_quiz' => 1]);
        }

        $index = (int) ($state['index'] ?? 0);
        if ($index >= count($cards)) {
            return redirect()->route('student.algebra-cards.show');
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
        $state['phase'] = 'teach';
        $request->session()->put(self::SESSION_KEY, $state);

        if ($state['index'] >= count($cards)) {
            $this->series->markAlgebraComplete($student, $packKey);
        }

        return redirect()->route('student.algebra-cards.show');
    }

    public function figure(string $pack, string $figure): BinaryFileResponse
    {
        $path = AlgebraCardPack::figurePath($pack, $figure);
        abort_if($path === null, 404);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'public, max-age=300, must-revalidate',
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>|null  $state
     */
    private function renderCompleted(array $meta, array $cards, $student, Request $request, ?array $state = null): Response
    {
        $score = (int) ($state['score'] ?? count($cards));
        $answered = (int) ($state['answered'] ?? count($cards));

        return Inertia::render('Student/AlgebraCards/Show', [
            'pack' => [
                'key' => $meta['key'],
                'label' => $meta['label'],
                'level' => $meta['level'],
            ],
            'play' => [
                'status' => 'completed',
                'phase' => 'done',
                'index' => count($cards),
                'total' => count($cards),
                'score' => $score,
                'answered' => $answered,
                'card' => null,
                'feedback' => null,
                'feedback_card' => null,
            ],
            'required_today' => false,
            'next_url' => route($this->nextAfter($student)),
            'next_label' => $this->basics->gatePassed($student) ? 'Continue to dashboard' : 'Continue to basics drill',
            'restart_url' => route('student.algebra-cards.show', ['restart' => 1]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<array<string, mixed>>  $cards
     * @param  array<string, mixed>  $state
     */
    private function renderPlay(array $meta, array $cards, array $state, $student): Response
    {
        $index = (int) ($state['index'] ?? 0);
        $current = $cards[$index] ?? null;
        $phase = (string) ($state['phase'] ?? 'teach');
        $feedback = $state['last_feedback'] ?? null;

        $feedbackCard = null;
        if (is_array($feedback) && isset($feedback['card_id'])) {
            $feedbackCard = collect($cards)->firstWhere('id', (int) $feedback['card_id']);
        }

        return Inertia::render('Student/AlgebraCards/Show', [
            'pack' => [
                'key' => $meta['key'],
                'label' => $meta['label'],
                'level' => $meta['level'],
            ],
            'play' => [
                'status' => 'in_progress',
                'phase' => $phase,
                'index' => min($index + 1, count($cards)),
                'total' => count($cards),
                'score' => (int) ($state['score'] ?? 0),
                'answered' => (int) ($state['answered'] ?? 0),
                'card' => $current ? [
                    'id' => $current['id'],
                    'title' => $current['title'],
                    'question' => $current['question'],
                    'check_lines' => $current['check_lines'] ?? [],
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
            'required_today' => $this->series->isRequiredToday($student),
            'next_url' => route($this->nextAfter($student)),
            'next_label' => $this->basics->gatePassed($student) ? 'Continue to dashboard' : 'Continue to basics drill',
            'restart_url' => route('student.algebra-cards.show', ['restart' => 1]),
        ]);
    }

    private function nextAfter($student): string
    {
        if (! $this->basics->gatePassed($student)) {
            return 'student.basics-drill.show';
        }

        return 'dashboard';
    }

    /**
     * @return array<string, mixed>
     */
    private function freshState(string $pack, int $total): array
    {
        return [
            'pack' => $pack,
            'index' => 0,
            'phase' => 'teach',
            'score' => 0,
            'answered' => 0,
            'total' => $total,
            'last_feedback' => null,
        ];
    }

    private function sessionMatches(Request $request, string $pack, int $total): bool
    {
        $state = $request->session()->get(self::SESSION_KEY);

        return is_array($state)
            && ($state['pack'] ?? null) === $pack
            && (int) ($state['total'] ?? 0) === $total;
    }
}
