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
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassHubController extends Controller
{
    public function __construct(private AdminGradeContext $gradeContext) {}

    public function index(Request $request): Response
    {
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
        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        $this->gradeContext->persist($request, $gradeLevel->id);

        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $syllabusVersion = null;
        $chapters = collect();
        if ($activeYear && $maths) {
            $syllabusVersion = SyllabusVersion::query()
                ->with(['board:id,code,name', 'subject:id,name'])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('subject_id', $maths->id)
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

        $view = $request->string('view')->toString();
        if (! in_array($view, ['topic', 'chapter'], true)) {
            $view = 'topic';
        }

        $chapterId = $request->integer('syllabus_chapter_id') ?: null;
        $topicId = $request->integer('syllabus_topic_id') ?: null;

        if ($topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $chapterFilterOptions = $chapters->map(fn ($ch) => [
            'id' => $ch['id'],
            'label' => "Ch {$ch['chapter_number']} — {$ch['name']}",
        ]);

        $chapterTopics = $chapterId
            ? SyllabusTopic::query()
                ->where('syllabus_chapter_id', $chapterId)
                ->withCount(['questions', 'practiceSets'])
                ->orderBy('sort_order')
                ->get(['id', 'name'])
                ->map(fn ($topic) => [
                    'id' => $topic->id,
                    'name' => $topic->name,
                    'questions_count' => $topic->questions_count,
                    'practice_sets_count' => $topic->practice_sets_count,
                ])
            : collect();

        $topicsQuery = SyllabusTopic::query()
            ->with(['chapter:id,syllabus_version_id,chapter_number,name,sort_order'])
            ->when($syllabusVersion, fn ($q) => $q->whereHas(
                'chapter',
                fn ($cq) => $cq->where('syllabus_version_id', $syllabusVersion->id),
            ))
            ->when($chapterId, fn ($q) => $q->where('syllabus_chapter_id', $chapterId))
            ->when($topicId, fn ($q) => $q->whereKey($topicId))
            ->withCount(['questions', 'practiceSets']);

        $topics = $topicsQuery->get()
            ->sortBy(fn (SyllabusTopic $topic) => [
                $topic->chapter?->sort_order ?? 0,
                $topic->sort_order ?? 0,
            ])
            ->values()
            ->map(fn ($topic) => [
            'id' => $topic->id,
            'name' => $topic->name,
            'chapter_id' => $topic->syllabus_chapter_id,
            'chapter_number' => $topic->chapter->chapter_number,
            'chapter_name' => $topic->chapter->name,
            'questions_count' => $topic->questions_count,
            'practice_sets_count' => $topic->practice_sets_count,
        ]);

        $filteredChapters = $chapters->when($chapterId, fn ($c) => $c->where('id', $chapterId))->values();

        $studentsCount = $activeYear
            ? StudentEnrollment::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('status', StudentEnrollment::STATUS_ACTIVE)
                ->count()
            : 0;

        return Inertia::render('Admin/Classes/Show', [
            'gradeLevel' => $gradeLevel->only(['id', 'name', 'sort_order']),
            'activeYear' => $activeYear?->only(['id', 'name']),
            'syllabusVersion' => $syllabusVersion ? [
                'id' => $syllabusVersion->id,
                'label' => $syllabusVersion->label(),
                'board' => $syllabusVersion->board,
            ] : null,
            'view' => $view,
            'selectedChapterId' => $chapterId,
            'selectedTopicId' => $topicId,
            'chapters' => $chapterFilterOptions,
            'chapterTopics' => $chapterTopics,
            'chapterRows' => $filteredChapters,
            'topics' => $topics,
            'stats' => [
                'chapters_count' => $chapters->count(),
                'topics_count' => $view === 'chapter' ? $filteredChapters->sum('topics_count') : $topics->count(),
                'questions_count' => $view === 'chapter' ? $filteredChapters->sum('questions_count') : $topics->sum('questions_count'),
                'practice_sets_count' => $view === 'chapter'
                    ? $filteredChapters->sum('topic_sets_count') + $filteredChapters->sum('chapter_tests_count')
                    : $topics->sum('practice_sets_count'),
                'students_count' => $studentsCount,
            ],
        ]);
    }
}
