<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\ChapterMixedQuestionService;
use App\Services\PracticeSetService;
use App\Support\PracticeSetScope;
use App\Support\PracticeSetTier;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PracticeSetController extends Controller
{
    public function __construct(
        private PracticeSetService $practiceSetService,
        private ChapterMixedQuestionService $mixedQuestionService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $grade = $this->gradeContext->resolve($request);

        $query = Worksheet::query()
            ->with([
                'topic.chapter.syllabusVersion.gradeLevel',
                'creator:id,name',
            ])
            ->withCount('questions')
            ->where(function ($q) {
                $q->whereNull('purpose')
                    ->orWhere('purpose', WorksheetPurpose::STANDARD);
            });

        $this->gradeContext->scopePracticeSets($query, $grade?->id);

        return Inertia::render('Admin/PracticeSets/Index', [
            'practiceSets' => $query
                ->orderBy('syllabus_topic_id')
                ->orderBy('set_number')
                ->get(),
            'tiers' => PracticeSetTier::options(),
            'selectedGrade' => $grade?->only(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $grade = $this->gradeContext->resolve($request);

        $scope = $request->string('scope')->toString();
        if (! in_array($scope, [PracticeSetScope::TOPIC, PracticeSetScope::CHAPTER], true)) {
            $scope = PracticeSetScope::TOPIC;
        }

        $chapterId = $request->integer('syllabus_chapter_id') ?: null;
        $topicId = $request->integer('syllabus_topic_id') ?: null;
        $sourceSetIds = collect($request->input('source_set_ids', []))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($scope === PracticeSetScope::TOPIC && $topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $chapters = $this->chapterOptions($grade?->id);
        $chapterTopics = $chapterId
            ? $this->topicsForChapter($chapterId)
            : collect();

        $sourceSets = ($scope === PracticeSetScope::CHAPTER && $chapterId)
            ? $this->sourceSetsForChapter($chapterId)
            : collect();

        $questions = ($scope === PracticeSetScope::CHAPTER && $sourceSetIds)
            ? $this->questionsFromSets($sourceSetIds, $chapterId)
            : collect();

        $topicBank = ($scope === PracticeSetScope::TOPIC && $topicId)
            ? $this->topicBankInfo($topicId)
            : null;

        $nextSetNumber = match ($scope) {
            PracticeSetScope::CHAPTER => $chapterId ? $this->practiceSetService->nextChapterSetNumber($chapterId) : null,
            default => $topicId ? $this->practiceSetService->nextSetNumber($topicId) : null,
        };

        return Inertia::render('Admin/PracticeSets/Create', [
            'chapters' => $chapters,
            'chapterTopics' => $chapterTopics,
            'scope' => $scope,
            'selectedChapterId' => $chapterId,
            'selectedTopicId' => $topicId,
            'selectedSourceSetIds' => $sourceSetIds,
            'sourceSets' => $sourceSets,
            'topicBank' => $topicBank,
            'nextSetNumber' => $nextSetNumber,
            'tiers' => PracticeSetTier::options(),
            'selectedGrade' => $grade?->only(['id', 'name']),
            'questions' => $questions,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $scope = $request->string('scope')->toString();

        if ($scope === PracticeSetScope::CHAPTER) {
            return $this->storeChapterSet($request);
        }

        return $this->storeTopicSet($request);
    }

    private function storeTopicSet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:'.PracticeSetScope::TOPIC],
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'tier' => ['required', 'in:'.implode(',', PracticeSetTier::topicTiers())],
            'notes' => ['nullable', 'string'],
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['exists:questions,id'],
            'status' => ['required', 'in:draft,published'],
        ]);

        $topic = SyllabusTopic::with('chapter.syllabusVersion.gradeLevel')->findOrFail($validated['syllabus_topic_id']);

        if ($topic->syllabus_chapter_id !== (int) $validated['syllabus_chapter_id']) {
            return back()->with('error', 'Selected topic does not belong to the chosen chapter.');
        }

        $allowedIds = Question::query()
            ->where('syllabus_topic_id', $topic->id)
            ->whereIn('id', $validated['question_ids'])
            ->pluck('id')
            ->all();

        if (count($allowedIds) !== count($validated['question_ids'])) {
            return back()->with('error', 'Some questions do not belong to this topic.');
        }

        $meta = $this->practiceSetService->prepareForCreate(
            $topic,
            $validated['tier'],
            count($validated['question_ids']),
            Question::idsAreAllFillInBlank($allowedIds),
        );

        $practiceSet = Worksheet::create([
            'title' => $meta['title'],
            'set_number' => $meta['set_number'],
            'set_code' => $meta['set_code'],
            'tier' => $validated['tier'],
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $validated['syllabus_topic_id'],
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'],
            'created_by' => $request->user()->id,
        ]);

        foreach ($validated['question_ids'] as $index => $questionId) {
            $practiceSet->questions()->attach($questionId, ['sort_order' => $index + 1]);
        }

        return redirect()
            ->route('admin.practice-sets.topics.show', $validated['syllabus_topic_id'])
            ->with('success', "{$meta['set_code']} created.");
    }

    private function storeChapterSet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:'.PracticeSetScope::CHAPTER],
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'notes' => ['nullable', 'string'],
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['exists:questions,id'],
            'status' => ['required', 'in:draft,published'],
        ]);

        $chapter = SyllabusChapter::findOrFail($validated['syllabus_chapter_id']);

        $allowedIds = Question::query()
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapter->id))
            ->whereIn('id', $validated['question_ids'])
            ->pluck('id')
            ->all();

        if (count($allowedIds) !== count($validated['question_ids'])) {
            return back()->with('error', 'Some questions do not belong to this chapter.');
        }

        $practiceSet = $this->practiceSetService->createChapterTest(
            $chapter,
            $validated['question_ids'],
            $request->user()->id,
            $validated['status'],
            $validated['notes'] ?? null,
        );

        return redirect()
            ->route('admin.practice-sets.chapters.show', $chapter->id)
            ->with('success', "{$practiceSet->set_code} chapter test created.");
    }

    public function show(Worksheet $worksheet): Response
    {
        $worksheet->load([
            'topic.chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.gradeLevel',
            'questions.options',
            'creator:id,name',
        ]);
        $worksheet->loadCount('questions');

        return Inertia::render('Admin/PracticeSets/Show', [
            'practiceSet' => $worksheet,
        ]);
    }

    public function storeFromTopic(Request $request, SyllabusTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'tier' => ['nullable', 'in:'.implode(',', PracticeSetTier::all())],
            'fill_in_blank' => ['nullable', 'boolean'],
        ]);

        $query = Question::query()
            ->where('syllabus_topic_id', $topic->id)
            ->where('bank_purpose', QuestionBankPurpose::PRACTICE_SET)
            ->whereDoesntHave('worksheets');

        if ($request->has('fill_in_blank')) {
            $fillInBlank = $request->boolean('fill_in_blank');
            $query->where('type', $fillInBlank ? Question::TYPE_FILL_IN_BLANK : Question::TYPE_MCQ);
        }

        $questionIds = $query->orderBy('id')->pluck('id')->all();

        if (count($questionIds) === 0) {
            return back()->with('error', 'No practice-set questions in this topic to package.');
        }

        $tier = $validated['tier'] ?? PracticeSetTier::STARTER;

        $meta = $this->practiceSetService->prepareForCreate(
            $topic,
            $tier,
            count($questionIds),
            Question::idsAreAllFillInBlank($questionIds),
        );

        $practiceSet = Worksheet::create([
            'title' => $meta['title'],
            'set_number' => $meta['set_number'],
            'set_code' => $meta['set_code'],
            'tier' => $tier,
            'scope' => PracticeSetScope::TOPIC,
            'syllabus_topic_id' => $topic->id,
            'status' => Worksheet::STATUS_PUBLISHED,
            'created_by' => $request->user()->id,
        ]);

        foreach ($questionIds as $index => $questionId) {
            $practiceSet->questions()->attach($questionId, ['sort_order' => $index + 1]);
        }

        return redirect()
            ->route('admin.questions.sets.show', $practiceSet)
            ->with('success', "{$meta['set_code']} created from question bank.");
    }

    public function destroy(Worksheet $worksheet): RedirectResponse
    {
        $topicId = $worksheet->syllabus_topic_id;
        $chapterId = $worksheet->syllabus_chapter_id;
        $isChapter = $worksheet->isChapterScope();
        $worksheet->delete();

        if ($isChapter && $chapterId) {
            return redirect()
                ->route('admin.practice-sets.chapters.show', $chapterId)
                ->with('success', 'Chapter test deleted.');
        }

        if ($topicId) {
            return redirect()
                ->route('admin.practice-sets.topics.show', $topicId)
                ->with('success', 'Practice set deleted.');
        }

        return redirect()
            ->route('admin.practice-sets.index')
            ->with('success', 'Practice set deleted.');
    }

    private function chapterOptions(?int $gradeLevelId)
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        if (! $activeYear || ! $maths) {
            return collect();
        }

        $query = SyllabusChapter::query()
            ->join('syllabus_versions', 'syllabus_versions.id', '=', 'syllabus_chapters.syllabus_version_id')
            ->join('grade_levels', 'grade_levels.id', '=', 'syllabus_versions.grade_level_id')
            ->join('boards', 'boards.id', '=', 'syllabus_versions.board_id')
            ->where('syllabus_versions.academic_year_id', $activeYear->id)
            ->where('syllabus_versions.subject_id', $maths->id)
            ->orderBy('grade_levels.sort_order')
            ->orderBy('syllabus_chapters.sort_order')
            ->select(
                'syllabus_chapters.id',
                'syllabus_chapters.chapter_number',
                'syllabus_chapters.name',
                'grade_levels.name as grade_name',
                'boards.code as board_code',
            );

        if ($gradeLevelId) {
            $query->where('syllabus_versions.grade_level_id', $gradeLevelId);
        }

        return $query->get()->map(fn ($chapter) => [
            'id' => $chapter->id,
            'label' => "{$chapter->board_code} {$chapter->grade_name} — Ch {$chapter->chapter_number} {$chapter->name}",
            'chapter_number' => $chapter->chapter_number,
            'name' => $chapter->name,
        ]);
    }

    private function topicsForChapter(int $chapterId)
    {
        return SyllabusTopic::query()
            ->where('syllabus_chapter_id', $chapterId)
            ->withCount('questions')
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn ($topic) => [
                'id' => $topic->id,
                'name' => $topic->name,
                'questions_count' => $topic->questions_count,
            ]);
    }

    private function topicBankInfo(int $topicId): array
    {
        $topic = SyllabusTopic::withCount('questions')->findOrFail($topicId);

        $existingSets = Worksheet::query()
            ->where('scope', PracticeSetScope::TOPIC)
            ->where('syllabus_topic_id', $topicId)
            ->withCount('questions')
            ->orderBy('set_number')
            ->get()
            ->map(fn (Worksheet $set) => [
                'id' => $set->id,
                'set_code' => $set->set_code,
                'tier_label' => $set->tier_label,
                'questions_count' => $set->questions_count,
                'status' => $set->status,
            ]);

        return [
            'questions_count' => $topic->questions_count,
            'existing_sets' => $existingSets,
        ];
    }

    private function sourceSetsForChapter(int $chapterId)
    {
        return Worksheet::query()
            ->where('scope', PracticeSetScope::TOPIC)
            ->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapterId))
            ->with('topic:id,name')
            ->withCount('questions')
            ->orderBy('set_code')
            ->get()
            ->map(fn (Worksheet $set) => [
                'id' => $set->id,
                'set_code' => $set->set_code,
                'tier_label' => $set->tier_label,
                'topic_name' => $set->topic?->name,
                'questions_count' => $set->questions_count,
                'status' => $set->status,
            ]);
    }

    private function questionsFromSets(array $setIds, ?int $chapterId)
    {
        if ($setIds === []) {
            return collect();
        }

        $query = Worksheet::query()
            ->whereIn('id', $setIds)
            ->where('scope', PracticeSetScope::TOPIC);

        if ($chapterId) {
            $query->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapterId));
        }

        $sets = $query->with(['questions.options'])->get();

        return $sets->flatMap(fn (Worksheet $set) => $set->questions->map(fn (Question $q) => [
            'id' => $q->id,
            'question_text' => $q->question_text,
            'diagram_url' => $q->diagram_url,
            'difficulty' => $q->difficulty,
            'set_code' => $set->set_code,
            'topic_name' => $set->topic?->name,
            'options' => $q->options,
        ]))->values();
    }
}
