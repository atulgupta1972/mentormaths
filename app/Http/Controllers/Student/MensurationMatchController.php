<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\MensurationMatchSession;
use App\Services\MensurationMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MensurationMatchController extends Controller
{
    public function __construct(
        private MensurationMatchService $mensuration,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $student = $user?->student;
        $enrollment = $student?->enrollments()->with('gradeLevel')->latest('id')->first();

        if (! $student || ! $enrollment) {
            return redirect()->route('dashboard')->with('error', 'Student enrollment required for Mensuration Match.');
        }

        $settings = $enrollment->gradeLevel
            ? $this->mensuration->settingsForGrade($enrollment->gradeLevel)
            : null;

        return Inertia::render('Student/MensurationMatch/Show', [
            'enabled' => (bool) ($settings?->enabled),
            'grade_name' => $enrollment->gradeLevel?->name,
            'boards' => $this->mensuration->boardsForEnrollment($enrollment),
            'play' => null,
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
        $enrollment = $student?->enrollments()->with('gradeLevel')->latest('id')->first();

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

        $enrollment = $student->enrollments()->with('gradeLevel')->latest('id')->first();
        if (! $enrollment) {
            return redirect()->route('student.mensuration-match.show')->with('error', 'Enrollment missing.');
        }

        return Inertia::render('Student/MensurationMatch/Show', [
            'enabled' => true,
            'grade_name' => $enrollment->gradeLevel?->name,
            'boards' => $this->mensuration->boardsForEnrollment($enrollment),
            'play' => $this->mensuration->playPayload($session, $enrollment),
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

        return back()->with([
            'success' => $result['correct'] ? 'Correct — '.$result['explanation'] : null,
            'mensuration_flash' => $result,
        ]);
    }
}
