<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\BasicsDrillItem;
use App\Models\BasicsDrillSession;
use App\Models\Student;
use App\Services\BasicsDrillSessionService;
use App\Services\FormulaDrillSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BasicsDrillController extends Controller
{
    public function __construct(
        private BasicsDrillSessionService $sessionService,
        private FormulaDrillSessionService $formulaService,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $student = $this->student($request);

        if (! $this->formulaService->drillsUnlocked($student)) {
            if ($this->formulaService->isFirstAccessDay($student)) {
                return redirect()
                    ->route('dashboard')
                    ->with('warning', 'No drills on your first day — mark your study plan today. Formula and basics drills start from tomorrow.');
            }

            return redirect()
                ->route('student.school-study-plan.show')
                ->with('warning', 'Mark your school study plan first (Studied / Under study). Daily drills unlock after that.');
        }

        $session = $this->sessionService->getOrCreateTodaysSession($student);
        $session->load(['items', 'student']);

        return Inertia::render('Student/BasicsDrill/Show', [
            'session' => $this->sessionService->formatSession($session),
        ]);
    }

    public function start(Request $request, BasicsDrillSession $session): JsonResponse
    {
        $this->authorizeSession($request, $session);
        $session = $this->sessionService->startDrill($session);
        $session->load(['items', 'student']);

        return response()->json([
            'session' => $this->sessionService->formatSession($session),
        ]);
    }

    public function submitAnswer(Request $request, BasicsDrillItem $item): JsonResponse
    {
        $session = $item->session;
        $this->authorizeSession($request, $session);

        $validated = $request->validate([
            'answer' => ['nullable', 'string', 'max:12'],
            'timed_out' => ['sometimes', 'boolean'],
        ]);

        $session->load(['items', 'student']);

        return response()->json(
            $this->sessionService->submitAnswer(
                $item,
                $validated['answer'] ?? null,
                (bool) ($validated['timed_out'] ?? false),
            ),
        );
    }

    public function submitMcqAnswer(Request $request, BasicsDrillItem $item): JsonResponse
    {
        $session = $item->session;
        $this->authorizeSession($request, $session);

        $validated = $request->validate([
            'option_id' => ['required', 'integer', 'exists:question_options,id'],
        ]);

        $session->load(['items', 'student']);

        return response()->json(
            $this->sessionService->submitCorrectionMcq($item, (int) $validated['option_id']),
        );
    }

    public function acknowledge(Request $request, BasicsDrillItem $item): JsonResponse
    {
        $session = $item->session;
        $this->authorizeSession($request, $session);
        $session->load(['items', 'student']);

        return response()->json(
            $this->sessionService->acknowledgeReveal($item),
        );
    }

    private function student(Request $request): Student
    {
        return $request->user()->student;
    }

    private function authorizeSession(Request $request, BasicsDrillSession $session): void
    {
        abort_unless(
            $request->user()->student?->id === $session->student_id,
            403,
        );
    }
}
