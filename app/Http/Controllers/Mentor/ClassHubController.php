<?php

namespace App\Http\Controllers\Mentor;

use App\Http\Controllers\Controller;
use App\Models\CoachingClass;
use App\Services\MentorClassHubService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassHubController extends Controller
{
    public function __construct(
        private MentorClassHubService $mentorClasses,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user?->isMentor() || $user?->isAdmin(), 403);

        return Inertia::render('Mentor/Classes/Index', [
            'classes' => $this->mentorClasses->classCards($user),
            'activeYear' => \App\Models\AcademicYear::active()?->only(['id', 'name']),
        ]);
    }

    public function show(Request $request, int $coachingClass): Response
    {
        $user = $request->user();
        abort_unless($user?->isMentor() || $user?->isAdmin(), 403);

        // Individual learners card uses id 0.
        if ($coachingClass < 0) {
            abort(404);
        }

        if ($coachingClass > 0) {
            CoachingClass::query()->findOrFail($coachingClass);
        }

        $examFilter = $request->string('exam_filter')->toString();
        $detail = $this->mentorClasses->classDetail($user, $coachingClass, $examFilter);

        return Inertia::render('Mentor/Classes/Show', $detail);
    }
}
