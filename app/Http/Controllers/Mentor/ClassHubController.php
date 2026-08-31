<?php

namespace App\Http\Controllers\Mentor;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Services\AdminGradeContext;
use App\Services\MentorClassHubService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassHubController extends Controller
{
    public function __construct(
        private MentorClassHubService $mentorClasses,
        private AdminGradeContext $gradeContext,
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

    public function show(Request $request, GradeLevel $gradeLevel): Response
    {
        $user = $request->user();
        abort_unless($user?->isMentor() || $user?->isAdmin(), 403);

        $this->gradeContext->persist($request, $gradeLevel->id);

        $examFilter = $request->string('exam_filter')->toString();
        $detail = $this->mentorClasses->classDetail($user, $gradeLevel, $examFilter);
        $enrollmentIds = $detail['enrollment_ids'] ?? [];
        unset($detail['enrollment_ids']);

        return Inertia::render('Mentor/Classes/Show', [
            ...$detail,
            'examPlanProgress' => Inertia::defer(
                fn () => $this->mentorClasses->studyPlanMetricsForEnrollmentIds($enrollmentIds),
            ),
        ]);
    }
}
