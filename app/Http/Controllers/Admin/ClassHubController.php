<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\StudentEnrollment;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\ClassAssignmentService;
use App\Services\ClassHubProgressService;
use App\Services\ExamPlanService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ClassHubController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private ExamPlanService $examPlanService,
        private ClassAssignmentService $classAssignmentService,
        private ClassHubProgressService $classHubProgress,
    ) {}

    public function index(Request $request): Response
    {
        $this->denyMentors($request);

        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $cards = $this->gradeContext->classLevels()->map(function (GradeLevel $grade) use ($activeYear, $maths) {
            $syllabus = null;

            if ($activeYear && $maths) {
                $syllabus = SyllabusVersion::query()
                    ->where('academic_year_id', $activeYear->id)
                    ->where('grade_level_id', $grade->id)
                    ->where('subject_id', $maths->id)
                    ->withCount('chapters')
                    ->first();
            }

            $topicQuery = SyllabusTopic::query();
            $this->gradeContext->scopeTopics($topicQuery, $grade->id);

            $questionQuery = Question::query();
            $this->gradeContext->scopeQuestions($questionQuery, $grade->id);

            $setQuery = Worksheet::query();
            $this->gradeContext->scopePracticeSets($setQuery, $grade->id);

            $students = 0;
            if ($activeYear) {
                $students = StudentEnrollment::query()
                    ->where('academic_year_id', $activeYear->id)
                    ->where('grade_level_id', $grade->id)
                    ->where('status', StudentEnrollment::STATUS_ACTIVE)
                    ->count();
            }

            return [
                'id' => $grade->id,
                'name' => $grade->name,
                'sort_order' => $grade->sort_order,
                'syllabus_version_id' => $syllabus?->id,
                'chapters_count' => $syllabus?->chapters_count ?? 0,
                'topics_count' => (clone $topicQuery)->count(),
                'questions_count' => (clone $questionQuery)->count(),
                'practice_sets_count' => (clone $setQuery)->count(),
                'students_count' => $students,
                'has_syllabus' => (bool) $syllabus,
            ];
        });

        return Inertia::render('Admin/Classes/Index', [
            'classes' => $cards,
            'activeYear' => $activeYear?->only(['id', 'name']),
        ]);
    }

    public function show(Request $request, GradeLevel $gradeLevel): Response
    {
        $this->denyMentors($request);

        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        if ($this->isClassHubDeferredProgressRequest($request, 'Admin/Classes/Show')) {
            return Inertia::render('Admin/Classes/Show', [
                'examPlanProgress' => $this->deferredExamPlanProgress(
                    $gradeLevel,
                    $this->enrollmentIdsForDeferredProgress($request, $gradeLevel),
                ),
            ]);
        }

        $this->gradeContext->persist($request, $gradeLevel->id);

        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $boardOptions = $this->classAssignmentService->boardsForGrade($gradeLevel);
        $boardId = $request->filled('board_id') ? ($request->integer('board_id') ?: null) : null;

        if ($boardId && ! collect($boardOptions)->contains(fn (array $board) => (int) $board['id'] === $boardId)) {
            $boardId = null;
        }

        $syllabusBoardId = $boardId ?: $this->classAssignmentService->defaultBoardIdForGrade($gradeLevel);
        $selectedBoard = collect($boardOptions)->firstWhere('id', $syllabusBoardId);

        $syllabusVersion = null;
        $chapters = collect();
        if ($activeYear && $maths && $syllabusBoardId) {
            $syllabusVersion = SyllabusVersion::query()
                ->with(['board:id,code,name', 'subject:id,name'])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('subject_id', $maths->id)
                ->where('board_id', $syllabusBoardId)
                ->first();

            if ($syllabusVersion) {
                $chapters = SyllabusChapter::query()
                    ->where('syllabus_version_id', $syllabusVersion->id)
                    ->withCount([
                        'topics',
                        'chapterPracticeSets',
                    ])
                    ->with(['topics' => fn ($q) => $q->withCount(['questions', 'practiceSets'])])
                    ->orderBy('sort_order')
                    ->get()
                    ->map(function (SyllabusChapter $chapter) {
                        $questionsCount = $chapter->topics->sum('questions_count');
                        $topicSetsCount = $chapter->topics->sum('practice_sets_count');

                        return [
                            'id' => $chapter->id,
                            'chapter_number' => $chapter->chapter_number,
                            'name' => $chapter->name,
                            'topics_count' => $chapter->topics_count,
                            'questions_count' => $questionsCount,
                            'topic_sets_count' => $topicSetsCount,
                            'chapter_tests_count' => $chapter->chapter_practice_sets_count,
                        ];
                    });
            }
        }

        $chapterId = $request->integer('syllabus_chapter_id') ?: null;

        $chapterFilterOptions = $chapters->map(fn ($ch) => [
            'id' => $ch['id'],
            'label' => "Ch {$ch['chapter_number']} — {$ch['name']}",
        ]);

        $filteredChapters = $chapters->when($chapterId, fn ($c) => $c->where('id', $chapterId))->values();

        $studentsCount = $activeYear
            ? StudentEnrollment::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->when($boardId, fn ($query) => $query->where('board_id', $boardId))
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->count()
            : 0;

        $examFilter = $request->string('exam_filter')->toString();
        if (! in_array($examFilter, ['upcoming', 'past', 'all'], true)) {
            $examFilter = 'upcoming';
        }

        $examPlanRows = [];
        $examPlanStats = ['with_upcoming' => 0, 'without_plan' => 0, 'without_upcoming' => 0];
        $loadError = null;

        $syllabusChapterOptions = $chapters->map(fn ($ch) => [
            'id' => $ch['id'],
            'label' => "Ch {$ch['chapter_number']} — {$ch['name']}",
        ])->values()->all();

        if ($activeYear) {
            $enrollments = collect();

            try {
                $enrollments = $this->examPlanService->activeEnrollmentForYear($activeYear->id, $gradeLevel->id, $boardId);
                $enrollments->load(['student:id,name,user_id', 'student.user:id,last_seen_at', 'academicYear']);
            } catch (Throwable $e) {
                Log::error('Admin class hub failed to load enrollments.', [
                    'grade_level_id' => $gradeLevel->id,
                    'board_id' => $boardId,
                    'message' => $e->getMessage(),
                ]);

                try {
                    $enrollments = StudentEnrollment::query()
                        ->with(['student:id,name,user_id', 'student.user:id,last_seen_at', 'academicYear'])
                        ->where('academic_year_id', $activeYear->id)
                        ->where('grade_level_id', $gradeLevel->id)
                        ->when($boardId, fn ($query) => $query->where('board_id', $boardId))
                        ->where('status', StudentEnrollment::STATUS_ACTIVE)
                        ->get()
                        ->sortBy(fn (StudentEnrollment $enrollment) => $enrollment->student?->name ?? '')
                        ->values();
                } catch (Throwable $fallbackError) {
                    Log::error('Admin class hub failed to load enrollments from fallback query.', [
                        'grade_level_id' => $gradeLevel->id,
                        'message' => $fallbackError->getMessage(),
                    ]);
                    $enrollments = collect();
                }

                $loadError = 'Could not load full student progress for this class. Showing the student list only.';
            }

            try {
                $examPlanRows = $this->examPlanService->classHubRows($enrollments, $examFilter, false);
            } catch (Throwable $e) {
                Log::error('Admin class hub failed to build student exam rows.', [
                    'grade_level_id' => $gradeLevel->id,
                    'board_id' => $boardId,
                    'message' => $e->getMessage(),
                ]);
                $examPlanRows = $this->studentNameRows($enrollments);
                $loadError ??= 'Could not load exam plans for this class. Student names are still shown.';
            }

            try {
                $examPlanRows = $this->classHubProgress->attachFast($enrollments, $examPlanRows);
                $examPlanRows = $this->classHubProgress->attachEngagement($enrollments, $examPlanRows);
            } catch (Throwable $e) {
                Log::error('Admin class hub failed to attach student progress.', [
                    'grade_level_id' => $gradeLevel->id,
                    'board_id' => $boardId,
                    'message' => $e->getMessage(),
                ]);
                $examPlanRows = $this->classHubProgress->withEmptyProgress($examPlanRows);
                $loadError ??= 'Could not load student progress for this class. Student names are still shown.';
            }

            $examPlanStats = [
                'with_upcoming' => collect($examPlanRows)->where('has_upcoming', true)->count(),
                'without_plan' => collect($examPlanRows)->where('has_plan', false)->count(),
                'without_upcoming' => collect($examPlanRows)->where('has_upcoming', false)->count(),
            ];
        }

        $enrollmentIds = collect($examPlanRows)->pluck('enrollment_id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        return Inertia::render('Admin/Classes/Show', [
            'gradeLevel' => $gradeLevel->only([
                'id',
                'name',
                'sort_order',
                'protect_test_attempts',
                'protect_practice_attempts',
            ]),
            'activeYear' => $activeYear?->only(['id', 'name']),
            'boardOptions' => $boardOptions,
            'selectedBoardId' => $boardId,
            'selectedBoard' => $selectedBoard,
            'syllabusVersion' => $syllabusVersion ? [
                'id' => $syllabusVersion->id,
                'label' => $syllabusVersion->label(),
                'board' => $syllabusVersion->board,
            ] : null,
            'selectedChapterId' => $chapterId,
            'chapters' => $chapterFilterOptions,
            'chapterRows' => $filteredChapters,
            'stats' => [
                'chapters_count' => $chapters->count(),
                'topics_count' => $filteredChapters->sum('topics_count'),
                'questions_count' => $filteredChapters->sum('questions_count'),
                'practice_sets_count' => $filteredChapters->sum('topic_sets_count') + $filteredChapters->sum('chapter_tests_count'),
                'students_count' => $studentsCount,
            ],
            'examFilter' => $examFilter,
            'examPlanRows' => $examPlanRows,
            'examPlanStats' => $examPlanStats,
            'syllabusChapterOptions' => $syllabusChapterOptions,
            'examTypeOptions' => $this->examPlanService->examTypeOptions(),
            'loadError' => $loadError,
            'examPlanProgress' => $this->deferredExamPlanProgress($gradeLevel, $enrollmentIds),
        ]);
    }

    public function updateAttemptProtection(Request $request, GradeLevel $gradeLevel): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        $validated = $request->validate([
            'protect_test_attempts' => ['required', 'boolean'],
            'protect_practice_attempts' => ['required', 'boolean'],
        ]);

        $gradeLevel->update($validated);

        return back()->with('success', 'Attempt protection settings saved for '.$gradeLevel->name.'.');
    }

    /**
     * @param  Collection<int, StudentEnrollment>  $enrollments
     * @return list<array<string, mixed>>
     */
    private function studentNameRows($enrollments): array
    {
        return $enrollments->map(fn (StudentEnrollment $enrollment) => [
            'student_id' => $enrollment->student_id,
            'student_name' => $enrollment->student?->name,
            'enrollment_id' => $enrollment->id,
            'has_plan' => false,
            'has_upcoming' => false,
            'upcoming_count' => 0,
            'display_plan' => null,
            'all_plans' => [],
        ])->values()->all();
    }

    private function denyMentors(Request $request): void
    {
        $user = $request->user();

        if ($user?->isMentor() && ! $user->isAdmin()) {
            throw new HttpResponseException(
                redirect()
                    ->route('mentor.classes.index')
                    ->with('warning', 'You can only see classes and students enrolled under you.')
            );
        }
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

    /**
     * @return list<int>
     */
    private function enrollmentIdsForDeferredProgress(Request $request, GradeLevel $gradeLevel): array
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return [];
        }

        $boardId = $request->filled('board_id') ? ($request->integer('board_id') ?: null) : null;

        return StudentEnrollment::query()
            ->where('academic_year_id', $activeYear->id)
            ->where('grade_level_id', $gradeLevel->id)
            ->when($boardId, fn ($query) => $query->where('board_id', $boardId))
            ->where('status', StudentEnrollment::STATUS_ACTIVE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $enrollmentIds
     */
    private function deferredExamPlanProgress(GradeLevel $gradeLevel, array $enrollmentIds): \Inertia\DeferProp
    {
        return Inertia::defer(function () use ($gradeLevel, $enrollmentIds) {
            try {
                if ($enrollmentIds === []) {
                    return [];
                }

                $enrollments = StudentEnrollment::query()
                    ->with(['student:id,name,user_id', 'student.user:id,last_seen_at', 'academicYear'])
                    ->whereIn('id', $enrollmentIds)
                    ->get();

                return $this->classHubProgress->studyPerformanceMetricsByEnrollment($enrollments);
            } catch (Throwable $e) {
                Log::error('Admin class hub failed to load deferred progress metrics.', [
                    'grade_level_id' => $gradeLevel->id,
                    'message' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }
}
