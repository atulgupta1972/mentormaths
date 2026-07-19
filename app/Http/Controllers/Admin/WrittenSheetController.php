<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\WrittenSheetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WrittenSheetController extends Controller
{
    public function __construct(
        private WrittenSheetService $writtenSheetService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        return Inertia::render('Admin/WrittenSheets/Index', [
            'sheets' => $this->writtenSheetService->listForAdmin($gradeLevel?->id)->values()->all(),
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $activeYear = AcademicYear::active();
        $chapters = [];

        if ($gradeLevel && $activeYear) {
            $syllabus = SyllabusVersion::query()
                ->with(['chapters' => fn ($q) => $q->orderBy('sort_order')])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->first();

            $chapters = $syllabus?->chapters->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'label' => "Ch {$chapter->chapter_number} — {$chapter->name}",
            ])->values()->all() ?? [];
        }

        $chapterId = $request->integer('chapter_id') ?: null;
        $topicId = $request->integer('topic_id') ?: null;
        $topics = [];
        $questions = [];

        if ($chapterId) {
            $chapter = SyllabusChapter::query()->with('topics')->find($chapterId);
            $topics = $chapter?->topics->map(fn ($topic) => [
                'id' => $topic->id,
                'name' => $topic->name,
            ])->values()->all() ?? [];

            $query = Question::query()->with('topic:id,name');

            if ($topicId) {
                $query->where('syllabus_topic_id', $topicId);
            } else {
                $query->whereHas('topic', fn ($q) => $q->where('syllabus_chapter_id', $chapterId));
            }

            $questions = $query->orderBy('id')->get()->map(fn (Question $question) => [
                'id' => $question->id,
                'topic_name' => $question->topic?->name,
                'type' => $question->type,
                'question_text' => strip_tags((string) $question->question_text),
            ])->values()->all();
        }

        return Inertia::render('Admin/WrittenSheets/Create', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'chapters' => $chapters,
            'topics' => $topics,
            'questions' => $questions,
            'filters' => [
                'chapter_id' => $chapterId,
                'topic_id' => $topicId,
                'sheet_kind' => $request->string('sheet_kind')->toString() ?: 'practice',
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sheet_kind' => ['required', 'in:practice,test'],
            'chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', 'exists:questions,id'],
            'notes' => ['nullable', 'string'],
        ]);

        try {
            if ($validated['sheet_kind'] === 'test') {
                $chapter = SyllabusChapter::query()->findOrFail($validated['chapter_id']);
                $worksheet = $this->writtenSheetService->createChapterTest(
                    $chapter,
                    $validated['question_ids'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            } else {
                $topicId = $validated['topic_id'] ?? null;

                if (! $topicId) {
                    return back()->with('error', 'Select a topic for a written practice sheet.');
                }

                $topic = SyllabusTopic::query()->findOrFail($topicId);
                $worksheet = $this->writtenSheetService->createFromTopic(
                    $topic,
                    $validated['question_ids'],
                    $request->user(),
                    $validated['notes'] ?? null,
                );
            }

            $this->writtenSheetService->generatePdf($worksheet);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.written-sheets.show', $worksheet)
            ->with('success', 'Written sheet created. Review the PDF, then verify to allow assigning.');
    }

    public function show(Worksheet $worksheet): Response
    {
        abort_unless($worksheet->isWritten(), 404);

        return Inertia::render('Admin/WrittenSheets/Show', [
            'sheet' => $this->writtenSheetService->detail($worksheet),
        ]);
    }

    public function regenerate(Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        try {
            $this->writtenSheetService->generatePdf($worksheet);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'PDF regenerated. Please review again before verifying.');
    }

    public function verify(Request $request, Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        try {
            $this->writtenSheetService->verify($worksheet, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Written sheet verified. You can now assign it to students.');
    }

    public function reject(Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isWritten(), 404);

        $this->writtenSheetService->reject($worksheet);

        return back()->with('success', 'Written sheet sent back to draft.');
    }

    public function download(Worksheet $worksheet): StreamedResponse
    {
        abort_unless($worksheet->isWritten() && $worksheet->written_pdf_path, 404);

        return Storage::disk('public')->download(
            $worksheet->written_pdf_path,
            ($worksheet->set_code ?: 'written-sheet').'.pdf',
        );
    }
}
