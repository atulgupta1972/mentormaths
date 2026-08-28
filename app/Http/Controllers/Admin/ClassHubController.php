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

        $this->gradeContext->persist($request, $gradeLevel->id);

        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $boardOptions = $this->classAssignmentService->boardsForGrade($gradeLevel);
        $boardId = $request->integer('board_id') ?: null;

        if ($boardId && ! collect($boardOptions)->contains(fn (array $board) => $board['id'] === $boardId)) {
            $boardId = null;
        }

        if (! $boardId) {
            $boardId = $this->classAssignmentService->defaultBoardIdForGrade($gradeLevel);
        }

        $selectedBoard = collect($boardOptions)->firstWhere('id', $boardId);

        $syllabusVersion = null;
        $chapters = collect();
        if ($activeYear && $maths && $boardId) {
            $syllabusVersion = SyllabusVersion::query()
                ->with(['board:id,code,name', 'subject:id,name'])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('subject_id', $maths->id)
                ->where('board_id', $boardId)
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

        $syllabusChapterOptions = $chapters->map(fn ($ch) => [
            'id' => $ch['id'],
            'label' => "Ch {$ch['chapter_number']} — {$ch['name']}",
        ])->values()->all();

        if ($activeYear) {
            try {
                $enrollments = $this->examPlanService->activeEnrollmentForYear($activeYear->id, $gradeLevel->id, $boardId);
                $enrollments->loadMissing(['student.user', 'academicYear']);
                $examPlanRows = $this->examPlanService->classHubRows($enrollments, $examFilter, true);
                $examPlanRows = $this->classHubProgress->attach($enrollments, $examPlanRows);
                $examPlanStats = [
                    'with_upcoming' => collect($examPlanRows)->where('has_upcoming', true)->count(),
                    'without_plan' => collect($examPlanRows)->where('has_plan', false)->count(),
                    'without_upcoming' => collect($examPlanRows)->where('has_upcoming', false)->count(),
                ];
            } catch (Throwable $e) {
                Log::error('Admin class hub failed to load student exam rows.', [
                    'grade_level_id' => $gradeLevel->id,
                    'board_id' => $boardId,
                    'message' => $e->getMessage(),
                ]);

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
                    'examPlanRows' => [],
                    'examPlanStats' => $examPlanStats,
                    'syllabusChapterOptions' => $syllabusChapterOptions,
                    'examTypeOptions' => $this->examPlanService->examTypeOptions(),
                    'loadError' => 'Could not load student progress for this class. If you recently deployed, run php artisan migrate --force on the server.',
                ]);
            }
        }

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
}
