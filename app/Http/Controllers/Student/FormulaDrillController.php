<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\FormulaDrillItem;
use App\Services\FormulaDrillSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FormulaDrillController extends Controller
{
    public function __construct(
        private FormulaDrillSessionService $sessionService,
    ) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $student = $request->user()->student;

        abort_unless($student, 403);

        $session = $this->sessionService->getOrCreateTodaysSession($student);

        if ($session->isComplete()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Student/FormulaDrill/Show', $this->sessionService->sessionPayload($session));
    }

    public function submitAnswer(Request $request, FormulaDrillItem $item): JsonResponse
    {
        $student = $request->user()->student;

        abort_unless($student, 403);

        $validated = $request->validate([
            'option_id' => ['required', 'integer', 'exists:question_options,id'],
        ]);

        $session = $this->sessionService->todaysSession($student);

        abort_unless($session && ! $session->isComplete(), 422, 'Today\'s formula drill is already complete.');

        abort_unless($item->formula_drill_session_id === $session->id, 403);
        abort_unless($session->student_id === $student->id, 403);

        $result = $this->sessionService->submitAnswer($session, $item, (int) $validated['option_id']);

        $session->refresh()->load(['items.question.options']);

        return response()->json([
            ...$result,
            'session' => $this->sessionService->sessionPayload($session),
        ]);
    }
}
