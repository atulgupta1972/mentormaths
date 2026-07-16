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
        $studentEnrollmentId = $request->integer('student_enrollment_id') ?: null;

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

        $weakStudentsForClass = $this->catchUpSetService->weakStudentsOverview(
            $grade?->id,
            $chapterId,
            $topicId,
        );

        $weakStudents = $studentEnrollmentId
            ? array_values(array_filter(
                $weakStudentsForClass,
                fn (array $row) => (int) $row['student_enrollment_id'] === $studentEnrollmentId,
            ))
            : $weakStudentsForClass;

        $overviewForClassCounts = $this->catchUpSetService->weakStudentsOverview(null, $chapterId, $topicId);
        $countsByGrade = collect($overviewForClassCounts)->groupBy('grade_level_id')->map->count();
        $gradeLevels = $this->gradeContext->classLevels()
            ->map(fn ($level) => [
                'id' => $level->id,
                'name' => $level->name,
                'weak_student_count' => (int) ($countsByGrade->get($level->id) ?? 0),
            ])
            ->filter(fn (array $level) => $level['weak_student_count'] > 0)
            ->values();

        $studentOptions = collect($weakStudentsForClass)
            ->map(fn (array $row) => [
                'student_enrollment_id' => $row['student_enrollment_id'],
                'student_name' => $row['student_name'],
                'grade_name' => $row['grade_name'],
            ])
            ->sortBy('student_name')
            ->values()
            ->all();

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
            'gradeLevels' => $gradeLevels,
            'studentOptions' => $studentOptions,
            'chapters' => $chapters,
            'topics' => $topics,
            'filters' => [
                'grade_level_id' => $grade?->id,
                'student_enrollment_id' => $studentEnrollmentId,
                'syllabus_chapter_id' => $chapterId,
                'syllabus_topic_id' => $topicId,
            ],
            'weakStudents' => $weakStudents,
            'recentCatchUps' => $recentCatchUps,
            'cursorPrompt' => session('catch_up_cursor_prompt'),
            'selectedEnrollmentIds' => session('catch_up_enrollment_ids', []),
        ]);
    }

    public function prompt(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', 'exists:student_enrollments,id'],
            'syllabus_chapter_id' => ['nullable', 'exists:syllabus_chapters,id'],
            'syllabus_topic_id' => ['nullable', 'exists:syllabus_topics,id'],
        ]);

        $grade = $this->gradeContext->resolve($request);
        $chapterId = ! empty($validated['syllabus_chapter_id']) ? (int) $validated['syllabus_chapter_id'] : null;
        $topicId = ! empty($validated['syllabus_topic_id']) ? (int) $validated['syllabus_topic_id'] : null;

        try {
            $prompt = $this->catchUpSetService->buildBatchPrompt(
                $validated['enrollment_ids'],
                $grade?->id,
                $chapterId,
                $topicId,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.catch-up.index', array_filter([
                'grade_level_id' => $grade?->id,
                'student_enrollment_id' => count($validated['enrollment_ids']) === 1
                    ? (int) $validated['enrollment_ids'][0]
                    : null,
                'syllabus_chapter_id' => $chapterId,
                'syllabus_topic_id' => $topicId,
            ]))
            ->with('catch_up_cursor_prompt', $prompt)
            ->with('catch_up_enrollment_ids', array_map('intval', $validated['enrollment_ids']))
            ->with('success', 'Catch-up prompt ready — copy into Cursor, then paste the JSON below.');
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'enrollment_ids' => ['required', 'array', 'min:1'],
            'enrollment_ids.*' => ['integer', 'exists:student_enrollments,id'],
            'syllabus_chapter_id' => ['nullable', 'exists:syllabus_chapters,id'],
            'syllabus_topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'json' => ['required', 'string'],
            'due_date' => ['required', 'date'],
        ]);

        $grade = $this->gradeContext->resolve($request);
        $chapterId = ! empty($validated['syllabus_chapter_id']) ? (int) $validated['syllabus_chapter_id'] : null;
        $topicId = ! empty($validated['syllabus_topic_id']) ? (int) $validated['syllabus_topic_id'] : null;

        try {
            $result = $this->catchUpSetService->importAndCreate(
                $validated['json'],
                $validated['enrollment_ids'],
                $request->user(),
                $validated['due_date'],
                $grade?->id,
                $chapterId,
                $topicId,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        }

        $codes = collect($result['created'])->pluck('set_code')->implode(', ');

        $request->session()->forget(['catch_up_cursor_prompt', 'catch_up_enrollment_ids']);

        return redirect()
            ->route('admin.catch-up.index', array_filter([
                'grade_level_id' => $grade?->id,
                'syllabus_chapter_id' => $chapterId,
                'syllabus_topic_id' => $topicId,
            ]))
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
