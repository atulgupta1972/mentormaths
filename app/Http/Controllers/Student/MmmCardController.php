<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\MmmCard;
use App\Services\MmmCardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MmmCardController extends Controller
{
    public function __construct(private MmmCardService $mmmCards) {}

    public function show(Request $request, MmmCard $mmmCard): Response
    {
        abort_unless($mmmCard->isPublished(), 404);

        $user = auth()->user();
        abort_unless($user?->isStudent(), 403);

        // Student may open any published MMM linked to at least one syllabus chapter
        // (access gated by being on study plan; card itself is method practice).
        abort_unless(
            $mmmCard->syllabusChapters()->exists(),
            404,
            'This MentorMaths method is not linked to a chapter yet.',
        );

        $exerciseIndex = $request->filled('exercise')
            ? max(0, $request->integer('exercise') - 1)
            : null;

        return Inertia::render('Student/MmmCards/Play', [
            ...$this->mmmCards->playPayload($mmmCard, $user, $exerciseIndex),
            'check_url' => route('student.mmm-cards.check', $mmmCard),
            'back_url' => route('student.dashboard'),
            'is_staff_preview' => false,
        ]);
    }

    public function check(Request $request, MmmCard $mmmCard)
    {
        abort_unless($mmmCard->isPublished(), 404);
        $user = auth()->user();
        abort_unless($user?->isStudent(), 403);

        $validated = $request->validate([
            'exercise_id' => ['required', 'integer', 'exists:mmm_card_exercises,id'],
            'rows' => ['required', 'array'],
            'grand_total' => ['nullable'],
        ]);

        $exercise = $mmmCard->exercises()->whereKey($validated['exercise_id'])->firstOrFail();
        $result = $this->mmmCards->checkSubmission($mmmCard, $exercise, $validated);

        if ($result['ok']) {
            $this->mmmCards->recordExercisePassed($user, $mmmCard, $exercise);
        }

        return response()->json($result);
    }
}
