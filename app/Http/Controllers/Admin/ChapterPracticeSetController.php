<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\Student;
use App\Models\SyllabusChapter;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\ChapterMixedQuestionService;
use App\Services\PracticeSetService;
use App\Services\SetAssignmentService;
use App\Support\PracticeSetScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChapterPracticeSetController extends Controller
{
    public function __construct(
        private PracticeSetService $practiceSetService,
        private ChapterMixedQuestionService $mixedQuestionService,
        private SetAssignmentService $assignmentService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function show(Request $request, SyllabusChapter $chapter): Response
    {
        $chapter->load([
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
            'topics' => fn ($q) => $q->withCount('questions')->orderBy('sort_order'),
            'chapterPracticeSets' => fn ($q) => $q->withCount('questions'),
        ]);

        $gradeLevel = $chapter->syllabusVersion?->gradeLevel;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $activeYear = AcademicYear::active();
        $selectedStudentId = $request->integer('student_id') ?: null;

        $students = $this->assignmentService->activeStudentsForAssignment($activeYear?->id);

        $studentProgress = [];

        if ($selectedStudentId && $activeYear) {
            $enrollment = Student::find($selectedStudentId)?->enrollmentForYear($activeYear->id);

            if ($enrollment) {
                $studentProgress = $this->assignmentService
                    ->studentProgressForChapter($enrollment->id, $chapter->id)
                    ->all();
            }
        }

        $progressBySetId = collect($studentProgress)->keyBy('practice_set_id');

        return Inertia::render('Admin/PracticeSets/ChapterHub', [
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
                'topics_count' => $chapter->topics->count(),
                'questions_count' => $chapter->topics->sum('questions_count'),
            ],
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'boardCode' => $chapter->syllabusVersion?->board?->code,
            'activeYear' => $chapter->syllabusVersion?->academicYear?->only(['id', 'name']),
            'topics' => $chapter->topics->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'questions_count' => $t->questions_count,
            ]),
            'chapterTests' => $chapter->chapterPracticeSets->map(fn ($set) => [
                'id' => $set->id,
                'set_code' => $set->set_code,
                'set_number' => $set->set_number,
                'display_title' => $set->display_title,
                'title' => $set->title,
                'status' => $set->status,
                'questions_count' => $set->questions_count,
                'student_progress' => $progressBySetId->get($set->id),
            ]),
            'students' => $students,
            'selectedStudentId' => $selectedStudentId,
            'studentProgress' => $studentProgress,
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request, SyllabusChapter $chapter): Response
    {
        $chapter->load(['syllabusVersion.board', 'syllabusVersion.gradeLevel', 'topics']);

        $gradeLevel = $chapter->syllabusVersion?->gradeLevel;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $questions = $this->mixedQuestionService->questionsForChapter($chapter);
        $nextSetNumber = $this->practiceSetService->nextChapterSetNumber($chapter->id);

        return Inertia::render('Admin/PracticeSets/CreateChapterTest', [
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
            ],
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'boardCode' => $chapter->syllabusVersion?->board?->code,
            'topics' => $chapter->topics->map(fn ($t) => ['id' => $t->id, 'name' => $t->name]),
            'questions' => $questions,
            'nextSetNumber' => $nextSetNumber,
            'defaultPerTopic' => 2,
        ]);
    }

    public function store(Request $request, SyllabusChapter $chapter): RedirectResponse
    {
        $validated = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['exists:questions,id'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published'],
        ]);

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

    public function storeAutoMix(Request $request, SyllabusChapter $chapter): RedirectResponse
    {
        $validated = $request->validate([
            'questions_per_topic' => ['required', 'integer', 'min:1', 'max:20'],
            'max_total' => ['nullable', 'integer', 'min:1', 'max:100'],
            'status' => ['required', 'in:draft,published'],
        ]);

        $questionIds = $this->mixedQuestionService->pickMixedQuestionIds(
            $chapter,
            $validated['questions_per_topic'],
            $validated['max_total'] ?? null,
        );

        if ($questionIds === []) {
            return back()->with('error', 'No questions in this chapter to build a mixed test.');
        }

        $practiceSet = $this->practiceSetService->createChapterTest(
            $chapter,
            $questionIds,
            $request->user()->id,
            $validated['status'],
        );

        return redirect()
            ->route('admin.questions.sets.show', $practiceSet)
            ->with('success', "{$practiceSet->set_code} auto-mixed from all topics.");
    }
}
