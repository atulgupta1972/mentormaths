<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\MensurationMatchSession;
use App\Services\BasicsDrillSessionService;
use App\Services\MensurationMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MensurationMatchController extends Controller
{
    public function __construct(
        private MensurationMatchService $mensuration,
        private BasicsDrillSessionService $basics,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $student = $user?->student;
        $enrollment = $student?->currentEnrollment()
            ?? $student?->enrollments()->with('gradeLevel')->latest('id')->first();
        $enrollment?->loadMissing('gradeLevel');

        if (! $student || ! $enrollment) {
            return redirect()->route('dashboard')->with('error', 'Student enrollment required for Mensuration Match.');
        }

        $boards = $this->mensuration->boardsForEnrollment($enrollment);
        $allDone = $boards !== [] && collect($boards)->every(fn (array $b) => ! empty($b['completed_today']));

        if ($allDone) {
            return redirect()
                ->route($this->nextAfterMensuration($student))
                ->with('success', 'Mensuration Match done — continuing your daily drills.');
        }

        try {
            $autoSession = $this->mensuration->sessionForAutoPlay($student, $enrollment);
        } catch (\InvalidArgumentException $e) {
            return redirect()->route('dashboard')->with('error', $e->getMessage());
        }

        if ($autoSession) {
            return redirect()->route('student.mensuration-match.play', $autoSession);
        }

        $settings = $enrollment->gradeLevel
            ? $this->mensuration->settingsForGrade($enrollment->gradeLevel)
            : null;

        return Inertia::render('Student/MensurationMatch/Show', [
            'enabled' => (bool) ($settings?->enabled),
            'grade_name' => $enrollment->gradeLevel?->name,
            'boards' => $boards,
            'play' => null,
            'required_today' => $this->mensuration->isRequiredToday($student),
            'all_done' => false,
            'next_url' => route($this->nextAfterMensuration($student)),
            'next_label' => $this->basics->gatePassed($student) ? 'Continue to dashboard' : 'Continue to basics drill',
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'board' => ['required', 'string', 'in:perimeter_area,volume'],
            'ready' => ['accepted'],
        ], [
            'ready.accepted' => 'Tick “I’m ready” to start the board.',
        ]);

        $user = $request->user();
        $student = $user?->student;
        $enrollment = $student?->currentEnrollment()
            ?? $student?->enrollments()->with('gradeLevel')->latest('id')->first();
        $enrollment?->loadMissing('gradeLevel');

        if (! $student || ! $enrollment) {
            return back()->with('error', 'Student enrollment required.');
        }

        try {
            $session = $this->mensuration->startBoard($student, $enrollment, $validated['board']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('student.mensuration-match.play', $session);
    }

    public function play(Request $request, MensurationMatchSession $session): Response|RedirectResponse
    {
        $user = $request->user();
        $student = $user?->student;
        abort_unless($student && (int) $session->student_id === (int) $student->id, 403);

        $enrollment = $student->currentEnrollment()
            ?? $student->enrollments()->with('gradeLevel')->latest('id')->first();
        $enrollment?->loadMissing('gradeLevel');
        if (! $enrollment) {
            return redirect()->route('student.mensuration-match.show')->with('error', 'Enrollment missing.');
        }

        $boards = $this->mensuration->boardsForEnrollment($enrollment);
        $allDone = $boards !== [] && collect($boards)->every(fn (array $b) => ! empty($b['completed_today']));

        if ($session->status === MensurationMatchSession::STATUS_COMPLETED && $allDone) {
            return redirect()
                ->route($this->nextAfterMensuration($student))
                ->with('success', 'Mensuration Match done — continuing your daily drills.');
        }

        if ($session->status === MensurationMatchSession::STATUS_COMPLETED) {
            $next = $this->mensuration->nextIncompleteSession($student, $enrollment);
            if ($next && (int) $next->id !== (int) $session->id) {
                return redirect()->route('student.mensuration-match.play', $next);
            }

            return redirect()->route('student.mensuration-match.show');
        }

        return Inertia::render('Student/MensurationMatch/Show', [
            'enabled' => true,
            'grade_name' => $enrollment->gradeLevel?->name,
            'boards' => $boards,
            'play' => $this->mensuration->playPayload($session, $enrollment),
            'required_today' => $this->mensuration->isRequiredToday($student),
            'all_done' => $allDone,
            'next_url' => route($this->nextAfterMensuration($student)),
            'next_label' => $this->basics->gatePassed($student) ? 'Continue to dashboard' : 'Continue to basics drill',
        ]);
    }

    public function answer(Request $request, MensurationMatchSession $session): RedirectResponse
    {
        $user = $request->user();
        $student = $user?->student;
        abort_unless($student && (int) $session->student_id === (int) $student->id, 403);

        $validated = $request->validate([
            'item_key' => ['required', 'string', 'max:64'],
            'formula' => ['required', 'string', 'max:64'],
        ]);

        try {
            $result = $this->mensuration->submitAnswer($session, $validated['item_key'], $validated['formula']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $session->refresh();

        if (! empty($result['done']) || $session->status === MensurationMatchSession::STATUS_COMPLETED) {
            $enrollment = $student->currentEnrollment()
                ?? $student->enrollments()->with('gradeLevel')->latest('id')->first();
            $enrollment?->loadMissing('gradeLevel');

            if ($enrollment) {
                $boards = $this->mensuration->boardsForEnrollment($enrollment);
                $allDone = $boards !== [] && collect($boards)->every(fn (array $b) => ! empty($b['completed_today']));

                if ($allDone) {
                    return redirect()
                        ->route($this->nextAfterMensuration($student))
                        ->with('success', 'Mensuration Match complete — on to the next drill.');
                }

                $next = $this->mensuration->nextIncompleteSession($student, $enrollment);
                if ($next) {
                    return redirect()
                        ->route('student.mensuration-match.play', $next)
                        ->with('success', 'Board complete — next board.');
                }
            }

            return redirect()
                ->route('student.mensuration-match.show')
                ->with('success', 'Board complete.');
        }

        return back()->with([
            'success' => $result['correct'] ? 'Correct — '.$result['explanation'] : null,
            'mensuration_flash' => $result,
        ]);
    }

    private function nextAfterMensuration($student): string
    {
        if (! $this->basics->gatePassed($student)) {
            return 'student.basics-drill.show';
        }

        return 'dashboard';
    }
}
