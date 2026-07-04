<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\PracticeSetCodeService;
use App\Services\QuestionMethodHintService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuestionHubController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private QuestionMethodHintService $methodHintService,
    ) {}

    public function classes(Request $request): Response
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();
        $grades = $this->gradeContext->classLevels();

        $boardSections = Board::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(function (Board $board) use ($activeYear, $maths, $grades) {
                $classes = $grades
                    ->map(fn (GradeLevel $grade) => $this->classCardForBoard($grade, $board, $activeYear, $maths))
                    ->filter(fn (array $card) => $card['has_syllabus'] || $card['topics_count'] > 0 || $card['questions_count'] > 0)
                    ->values();

                return [
                    'id' => $board->id,
                    'code' => $board->code,
                    'name' => $board->name,
                    'classes' => $classes,
                ];
            })
            ->filter(fn (array $section) => $section['classes']->isNotEmpty())
            ->values();

        return Inertia::render('Admin/Questions/Hub/Classes', [
            'boardSections' => $boardSections,
            'activeYear' => $activeYear?->only(['id', 'name']),
        ]);
    }

    public function chapters(Request $request, GradeLevel $gradeLevel): Response
    {
        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        $this->gradeContext->persist($request, $gradeLevel->id);

        $boardId = $request->integer('board_id');
        abort_unless($boardId, 404);

        $board = Board::query()->whereKey($boardId)->where('is_active', true)->firstOrFail();

        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $syllabusVersion = null;
        $chapters = collect();

        if ($activeYear && $maths) {
            $syllabusVersion = SyllabusVersion::query()
                ->with('board:id,code,name')
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('subject_id', $maths->id)
                ->where('board_id', $board->id)
                ->first();

            if ($syllabusVersion) {
                $chapters = SyllabusChapter::query()
                    ->where('syllabus_version_id', $syllabusVersion->id)
                    ->with(['topics' => fn ($q) => $q->withCount('questions')->orderBy('sort_order')])
                    ->orderBy('sort_order')
                    ->get()
                    ->map(fn (SyllabusChapter $chapter) => [
                        'id' => $chapter->id,
                        'chapter_number' => $chapter->chapter_number,
                        'name' => $chapter->name,
                        'topics_count' => $chapter->topics->count(),
                        'questions_count' => $chapter->topics->sum('questions_count'),
                    ]);
            }
        }

        return Inertia::render('Admin/Questions/Hub/Chapters', [
            'gradeLevel' => $gradeLevel->only(['id', 'name']),
            'board' => $board->only(['id', 'code', 'name']),
            'activeYear' => $activeYear?->only(['id', 'name']),
            'syllabusVersion' => $syllabusVersion ? [
                'id' => $syllabusVersion->id,
                'board_code' => $syllabusVersion->board->code,
                'board_name' => $syllabusVersion->board->name,
            ] : null,
            'chapters' => $chapters,
            'stats' => [
                'chapters_count' => $chapters->count(),
                'topics_count' => $chapters->sum('topics_count'),
                'questions_count' => $chapters->sum('questions_count'),
            ],
        ]);
    }

    public function topics(Request $request, SyllabusChapter $chapter): Response
    {
        $chapter->load([
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $gradeLevel = $chapter->syllabusVersion?->gradeLevel;

        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $codeService = app(PracticeSetCodeService::class);
        $browseOnly = ! $request->user()?->isAdmin();

        $chapterTests = Worksheet::query()
            ->where('scope', PracticeSetScope::CHAPTER)
            ->where('syllabus_chapter_id', $chapter->id)
            ->when($browseOnly, fn ($q) => $q->where('status', Worksheet::STATUS_PUBLISHED))
            ->withCount('questions')
            ->orderBy('set_number')
            ->get()
            ->map(fn (Worksheet $set) => [
                'type' => 'chapter_test',
                'id' => $set->id,
                'set_code' => $set->set_code,
                'tier' => $set->tier,
                'tier_label' => $set->tier_label,
                'questions_count' => $set->questions_count,
                'status' => $set->status,
            ]);

        $topicModels = $chapter->topics()
            ->withCount('questions')
            ->with(['practiceSets' => fn ($q) => $q
                ->when($browseOnly, fn ($inner) => $inner->where('status', Worksheet::STATUS_PUBLISHED))
                ->withCount('questions')
                ->orderBy('set_number')])
            ->orderBy('sort_order')
            ->get();

        $setCards = collect();

        foreach ($topicModels as $topic) {
            foreach ($topic->practiceSets as $set) {
                $setCards->push([
                    'type' => 'set',
                    'id' => $set->id,
                    'topic_id' => $topic->id,
                    'topic_name' => $topic->name,
                    'set_code' => $set->set_code,
                    'tier' => $set->tier,
                    'tier_label' => $set->tier_label,
                    'questions_count' => $set->questions_count,
                    'status' => $set->status,
                ]);
            }

            if ($topic->practiceSets->isEmpty() && $topic->questions_count > 0) {
                $setCards->push([
                    'type' => 'bank',
                    'topic_id' => $topic->id,
                    'topic_name' => $topic->name,
                    'set_code' => $codeService->generate($topic, PracticeSetTier::STARTER),
                    'tier' => PracticeSetTier::STARTER,
                    'tier_label' => PracticeSetTier::label(PracticeSetTier::STARTER),
                    'questions_count' => $topic->questions_count,
                    'status' => 'bank',
                ]);
            }
        }

        return Inertia::render('Admin/Questions/Hub/Topics', [
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
            ],
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'boardCode' => $chapter->syllabusVersion?->board?->code,
            'activeYear' => $chapter->syllabusVersion?->academicYear?->only(['id', 'name']),
            'setCards' => $setCards->values()->all(),
            'chapterTests' => $chapterTests->values()->all(),
            'stats' => [
                'topics_count' => $topicModels->count(),
                'questions_count' => $topicModels->sum('questions_count'),
                'sets_count' => $setCards->where('type', 'set')->count(),
                'chapter_tests_count' => $chapterTests->count(),
            ],
        ]);
    }

    public function setQuestions(Request $request, Worksheet $worksheet): Response
    {
        if (! $request->user()?->isAdmin() && $worksheet->status !== Worksheet::STATUS_PUBLISHED) {
            abort(403, 'This practice set is not available for preview.');
        }

        $worksheet->load([
            'topic.chapter.syllabusVersion.board',
            'topic.chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.board',
            'chapter.syllabusVersion.gradeLevel',
            'questions.options',
        ]);
        $worksheet->loadCount('questions');

        $topic = $worksheet->topic;
        $chapter = $worksheet->chapter ?? $topic?->chapter;
        $gradeLevel = $chapter?->syllabusVersion?->gradeLevel;

        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        return Inertia::render('Admin/Questions/SetQuestions', [
            'practiceSet' => [
                'id' => $worksheet->id,
                'set_code' => $worksheet->set_code,
                'set_number' => $worksheet->set_number,
                'tier_label' => $worksheet->tier_label,
                'tier_tagline' => $worksheet->tier_tagline,
                'display_title' => $worksheet->display_title,
                'status' => $worksheet->status,
                'questions_count' => $worksheet->questions_count,
            ],
            'topic' => $topic ? [
                'id' => $topic->id,
                'name' => $topic->name,
                'chapter_id' => $topic->syllabus_chapter_id,
                'chapter_number' => $topic->chapter->chapter_number,
                'chapter_name' => $topic->chapter->name,
                'grade_name' => $gradeLevel?->name,
                'board_code' => $topic->chapter->syllabusVersion?->board?->code,
            ] : ($chapter ? [
                'id' => null,
                'name' => 'Chapter test (mixed topics)',
                'chapter_id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'chapter_name' => $chapter->name,
                'grade_name' => $gradeLevel?->name,
                'board_code' => $chapter->syllabusVersion?->board?->code,
            ] : null),
            'isChapterTest' => $worksheet->isChapterScope(),
            'questions' => $worksheet->questions->map(fn ($q) => [
                'id' => $q->id,
                'question_text' => $q->question_text,
                'diagram_url' => $q->diagram_url,
                'difficulty' => $q->difficulty,
                'source' => $q->source,
                'method_hint' => $q->method_hint,
                'options_count' => $q->options->count(),
            ])->values()->all(),
            'hintStats' => $topic
                ? $this->methodHintService->statsForQuestions($worksheet->questions)
                : null,
            'topicHintStats' => $topic
                ? $this->methodHintService->statsForTopic($topic)
                : null,
        ]);
    }

    private function classCardForBoard(
        GradeLevel $grade,
        Board $board,
        ?AcademicYear $activeYear,
        ?Subject $maths,
    ): array {
        $syllabus = null;
        $chaptersCount = 0;

        if ($activeYear && $maths) {
            $syllabus = SyllabusVersion::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $grade->id)
                ->where('subject_id', $maths->id)
                ->where('board_id', $board->id)
                ->withCount('chapters')
                ->first();
            $chaptersCount = $syllabus?->chapters_count ?? 0;
        }

        $topicQuery = SyllabusTopic::query()->whereHas(
            'chapter.syllabusVersion',
            fn (Builder $query) => $query
                ->where('grade_level_id', $grade->id)
                ->where('board_id', $board->id),
        );

        $questionQuery = Question::query()->whereHas(
            'topic.chapter.syllabusVersion',
            fn (Builder $query) => $query
                ->where('grade_level_id', $grade->id)
                ->where('board_id', $board->id),
        );

        return [
            'id' => $grade->id,
            'name' => $grade->name,
            'board_id' => $board->id,
            'chapters_count' => $chaptersCount,
            'topics_count' => (clone $topicQuery)->count(),
            'questions_count' => (clone $questionQuery)->count(),
            'has_syllabus' => (bool) $syllabus,
        ];
    }
}
