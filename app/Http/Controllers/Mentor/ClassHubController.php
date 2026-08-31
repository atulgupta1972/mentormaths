<?php

namespace App\Http\Controllers\Mentor;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Services\AdminGradeContext;
use App\Services\MentorClassHubService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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

        if ($this->isClassHubDeferredProgressRequest($request, 'Mentor/Classes/Show')) {
            return Inertia::render('Mentor/Classes/Show', [
                'examPlanProgress' => Inertia::defer(function () use ($user, $gradeLevel) {
                    try {
                        return $this->mentorClasses->studyPlanMetricsForEnrollmentIds(
                            $this->mentorClasses->enrollmentIdsForGrade($user, $gradeLevel),
                        );
                    } catch (Throwable $e) {
                        Log::error('Mentor class hub failed to load deferred progress metrics.', [
                            'grade_level_id' => $gradeLevel->id,
                            'message' => $e->getMessage(),
                        ]);

                        return [];
                    }
                }),
            ]);
        }

        $examFilter = $request->string('exam_filter')->toString();
        $detail = $this->mentorClasses->classDetail($user, $gradeLevel, $examFilter);
        $enrollmentIds = $detail['enrollment_ids'] ?? [];
        unset($detail['enrollment_ids']);

        return Inertia::render('Mentor/Classes/Show', [
            ...$detail,
            'examPlanProgress' => Inertia::defer(function () use ($enrollmentIds) {
                try {
                    return $this->mentorClasses->studyPlanMetricsForEnrollmentIds($enrollmentIds);
                } catch (Throwable $e) {
                    Log::error('Mentor class hub failed to load deferred progress metrics.', [
                        'message' => $e->getMessage(),
                    ]);

                    return [];
                }
            }),
        ]);
    }

    /**
     * Inertia deferred loads for student metrics should not rerun the full class hub query.
     */
    private function isClassHubDeferredProgressRequest(Request $request, string $component): bool
    {
        if (! $request->header('X-Inertia')
            || $request->header('X-Inertia-Partial-Component') !== $component) {
            return false;
        }

        $only = array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $request->header('X-Inertia-Partial-Data', '')),
        )));

        return $only === ['examPlanProgress'];
    }
}
