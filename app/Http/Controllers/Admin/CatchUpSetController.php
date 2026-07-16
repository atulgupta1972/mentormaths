<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\CatchUpSetService;
use App\Support\WorksheetPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CatchUpSetController extends Controller
{
    public function __construct(
        private CatchUpSetService $catchUpSetService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $grade = $this->gradeContext->resolve($request);
        $topicId = $request->integer('syllabus_topic_id') ?: null;
        $chapterId = $request->integer('syllabus_chapter_id') ?: null;

        if ($topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $chapters = $this->chapterOptions($grade?->id);
        $topics = $chapterId
            ? SyllabusTopic::query()
                ->where('syllabus_chapter_id', $chapterId)
                ->orderBy('sort_order')
                ->get(['id', 'name'])
            : collect();

        $topic = $topicId ? SyllabusTopic::with('chapter')->find($topicId) : null;
        $weakStudents = $topic ? $this->catchUpSetService->weakStudentsForTopic($topic) : [];

        $recentCatchUps = Worksheet::query()
            ->where('purpose', WorksheetPurpose::CATCH_UP)
            ->with([
                'topic:id,name',
                'catchUpEnrollment.student:id,name',
            ])
            ->withCount('questions')
            ->when($grade, function ($query) use ($grade) {
                $query->whereHas('topic.chapter.syllabusVersion', fn ($q) => $q->where('grade_level_id', $grade->id));
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (Worksheet $set) => [
                'id' => $set->id,
                'set_code' => $set->set_code,
                'topic_name' => $set->topic?->name,
                'student_name' => $set->catchUpEnrollment?->student?->name,
                'questions_count' => $set->questions_count,
                'created_at' => $set->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Admin/PracticeSets/CatchUp', [
            'selectedGrade' => $grade?->only(['id', 'name']),
            'chapters' => $chapters,
            'topics' => $topics,
            'filters' => [
                'syllabus_chapter_id' => $chapterId,
                'syllabus_topic_id' => $topicId,
            ],
            'topic' => $topic ? [
                'id' => $topic->id,
                'name' => $topic->name,
                'chapter_name' => $topic->chapter?->name,
            ] : null,
            'weakStudents' => $weakStudents,
            'recentCatchUps' => $recentCatchUps,
            'cursorPrompt' => session('catch_up_cursor_prompt'),
            'selectedEnrollmentIds' => session('catch_up_enrollment_ids', []),
        ]);
    }

    public function prompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', 'exists:student_enrollments,id'],
        ]);

        $topic = SyllabusTopic::findOrFail($validated['syllabus_topic_id']);

        try {
            $prompt = $this->catchUpSetService->buildBatchPrompt($topic, $validated['enrollment_ids']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.catch-up.index', [
                'syllabus_chapter_id' => $topic->syllabus_chapter_id,
                'syllabus_topic_id' => $topic->id,
            ])
            ->with('catch_up_cursor_prompt', $prompt)
            ->with('catch_up_enrollment_ids', array_map('intval', $validated['enrollment_ids']))
            ->with('success', 'Catch-up prompt ready — copy into Cursor, then paste the JSON below.');
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', 'exists:student_enrollments,id'],
            'json' => ['required', 'string'],
            'due_date' => ['required', 'date'],
        ]);

        $topic = SyllabusTopic::findOrFail($validated['syllabus_topic_id']);

        try {
            $result = $this->catchUpSetService->importAndCreate(
                $topic,
                $validated['json'],
                $validated['enrollment_ids'],
                $request->user(),
                $validated['due_date'],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $codes = collect($result['created'])->pluck('set_code')->implode(', ');

        $request->session()->forget(['catch_up_cursor_prompt', 'catch_up_enrollment_ids']);

        return redirect()
            ->route('admin.catch-up.index', [
                'syllabus_chapter_id' => $topic->syllabus_chapter_id,
                'syllabus_topic_id' => $topic->id,
            ])
            ->with('success', 'Created catch-up sets: '.$codes.'. Students can open them under Catch-up Sets on their dashboard.');
    }


    private function chapterOptions(?int $gradeLevelId)
    {
        $query = SyllabusChapter::query()
            ->with(['syllabusVersion.gradeLevel:id,name'])
            ->orderBy('chapter_number');

        if ($gradeLevelId) {
            $query->whereHas('syllabusVersion', fn ($q) => $q->where('grade_level_id', $gradeLevelId));
        }

        return $query->get()->map(fn (SyllabusChapter $chapter) => [
            'id' => $chapter->id,
            'name' => $chapter->name,
            'chapter_number' => $chapter->chapter_number,
            'grade_name' => $chapter->syllabusVersion?->gradeLevel?->name,
        ]);
    }
}
