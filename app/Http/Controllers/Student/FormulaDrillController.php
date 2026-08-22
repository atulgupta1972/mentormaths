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
            'option_id' => ['nullable', 'integer', 'exists:question_options,id'],
            'answer_text' => ['nullable', 'string', 'max:64'],
        ]);

        $session = $this->sessionService->todaysSession($student);

        abort_unless($session && ! $session->isComplete(), 422, 'Today\'s formula drill is already complete.');

        abort_unless($item->formula_drill_session_id === $session->id, 403);
        abort_unless($session->student_id === $student->id, 403);

        $result = $this->sessionService->submitAnswer(
            $session,
            $item,
            isset($validated['option_id']) ? (int) $validated['option_id'] : null,
            $validated['answer_text'] ?? null,
        );

        $session->refresh()->load(['items.question.options', 'items.question.blankAnswer']);

        return response()->json([
            ...$result,
            'session' => $this->sessionService->sessionPayload($session),
        ]);
    }

    public function requestTeacherHelp(Request $request, FormulaDrillItem $item): JsonResponse
    {
        $student = $request->user()->student;

        abort_unless($student, 403);

        $session = $this->sessionService->todaysSession($student);

        abort_unless($session && ! $session->isComplete(), 422, 'Today\'s formula drill is already complete.');

        abort_unless($item->formula_drill_session_id === $session->id, 403);
        abort_unless($session->student_id === $student->id, 403);

        $result = $this->sessionService->requestTeacherHelp($session, $item);

        return response()->json($result);
    }

    public function reportIssue(Request $request, FormulaDrillItem $item): JsonResponse
    {
        $student = $request->user()->student;

        abort_unless($student, 403);

        $session = $this->sessionService->todaysSession($student);

        abort_unless($session && ! $session->isComplete(), 422, 'Today\'s formula drill is already complete.');

        abort_unless($item->formula_drill_session_id === $session->id, 403);
        abort_unless($session->student_id === $student->id, 403);

        try {
            $result = app(\App\Services\QuestionIssueReportService::class)
                ->reportFromFormulaDrill($student, $item, $this->sessionService);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }
}
