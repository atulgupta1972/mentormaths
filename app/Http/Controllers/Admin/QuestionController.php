<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Services\AdminGradeContext;
use App\Services\McqImportService;
use App\Services\PdfTextExtractionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class QuestionController extends Controller
{
    public function __construct(
        private McqImportService $importService,
        private PdfTextExtractionService $pdfService,
        private AdminGradeContext $gradeContext,
    ) {}

    public function topicIndex(Request $request, SyllabusTopic $topic): Response
    {
        $topic->load(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel']);

        $gradeLevel = $topic->chapter?->syllabusVersion?->gradeLevel;
        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        $query = Question::query()
            ->with('options')
            ->where('syllabus_topic_id', $topic->id)
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search')->toString();
                $q->where('question_text', 'like', "%{$search}%");
            });

        $questions = $query->latest()->paginate(20)->withQueryString();

        return Inertia::render('Admin/Questions/TopicQuestions', [
            'topic' => [
                'id' => $topic->id,
                'name' => $topic->name,
                'difficulty' => $topic->difficulty,
                'chapter_id' => $topic->syllabus_chapter_id,
                'chapter_number' => $topic->chapter->chapter_number,
                'chapter_name' => $topic->chapter->name,
                'grade_name' => $gradeLevel?->name,
                'board_code' => $topic->chapter->syllabusVersion?->board?->code,
            ],
            'questions' => $questions,
            'filters' => [
                'search' => $request->string('search')->toString(),
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->renderCreate($request);
    }

    public function extractPdf(Request $request): Response
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'pdf' => ['required', 'file', 'max:10240'],
            'pdf_mode' => ['required', 'in:sums,mcq'],
        ], [
            'pdf.required' => 'Choose a PDF file first.',
            'pdf.max' => 'PDF must be smaller than 10 MB.',
            'syllabus_topic_id.required' => 'Select a syllabus topic before uploading.',
        ]);

        if (! $this->isPdfUpload($request->file('pdf'))) {
            return $this->renderCreate($request, [
                'error' => 'Please upload a valid PDF file (.pdf).',
            ]);
        }

        $topic = SyllabusTopic::with(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear'])
            ->findOrFail($validated['syllabus_topic_id']);

        try {
            $text = $this->pdfService->extract($request->file('pdf'));
        } catch (\InvalidArgumentException $e) {
            return $this->renderCreate($request, [
                'error' => $e->getMessage(),
                'importMode' => $validated['pdf_mode'] === 'mcq' ? 'pdf_mcq' : 'pdf_sums',
                'selectedTopicId' => $topic->id,
            ]);
        }

        $prompt = $validated['pdf_mode'] === 'mcq'
            ? $this->importService->cursorPromptFromMcqPdf($topic, $text)
            : $this->importService->cursorPromptFromSumsPdf($topic, $text);

        $overrides = [
            'cursorPrompt' => $prompt,
            'extractedPreview' => mb_substr($text, 0, 3000),
            'pdfFileName' => $request->file('pdf')->getClientOriginalName(),
            'importMode' => $validated['pdf_mode'] === 'mcq' ? 'pdf_mcq' : 'pdf_sums',
            'selectedTopicId' => $topic->id,
            'pdfExtracted' => true,
        ];

        if ($validated['pdf_mode'] === 'mcq') {
            try {
                $directRows = $this->importService->parseFromWorksheetText($text);
                if ($directRows !== []) {
                    $overrides['importRows'] = $directRows;
                    $overrides['pdfDirectParsed'] = true;
                }
            } catch (\InvalidArgumentException) {
                // Fall back to Cursor prompt flow.
            }
        }

        return $this->renderCreate($request, $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function renderCreate(Request $request, array $overrides = []): Response
    {
        $grade = $this->gradeContext->resolve($request);
        $topicId = $overrides['selectedTopicId']
            ?? ($request->integer('syllabus_topic_id') ?: null);
        $chapterId = $request->integer('syllabus_chapter_id') ?: null;

        if ($topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $topic = $topicId
            ? SyllabusTopic::with(['chapter.syllabusVersion.board', 'chapter.syllabusVersion.gradeLevel', 'chapter.syllabusVersion.academicYear'])->find($topicId)
            : null;

        $promptOptions = [
            'total' => max(1, min(50, $request->integer('total') ?: 6)),
            'easy' => max(0, $request->integer('easy') ?: 2),
            'medium' => max(0, $request->integer('medium') ?: 2),
            'hard' => max(0, $request->integer('hard') ?: 2),
            'focus' => $request->string('focus')->toString(),
        ];

        $importMode = $overrides['importMode'] ?? $request->string('mode')->toString();
        if (! in_array($importMode, ['custom', 'pdf_sums', 'pdf_mcq'], true)) {
            $importMode = 'custom';
        }

        $activePrompt = $overrides['cursorPrompt'] ?? null;
        if ($activePrompt === null && $topic) {
            $activePrompt = $this->importService->cursorPrompt($topic, $promptOptions);
        }

        $chapters = $this->chapterOptions($grade?->id);

        return Inertia::render('Admin/Questions/Create', [
            'chapters' => $chapters,
            'chapterTopics' => $chapterId ? $this->topicsForChapter($chapterId) : collect(),
            'topicsByChapter' => $chapters->mapWithKeys(fn ($chapter) => [
                $chapter['id'] => $this->topicsForChapter($chapter['id'])->values()->all(),
            ])->all(),
            'selectedChapterId' => $chapterId,
            'selectedTopicId' => $topicId,
            'topic' => $topic,
            'selectedGrade' => $grade?->only(['id', 'name']),
            'cursorPrompt' => $activePrompt,
            'promptOptions' => $promptOptions,
            'importMode' => $importMode,
            'extractedPreview' => $overrides['extractedPreview'] ?? null,
            'pdfFileName' => $overrides['pdfFileName'] ?? null,
            'pdfExtracted' => (bool) ($overrides['pdfExtracted'] ?? false),
            'pdfDirectParsed' => (bool) ($overrides['pdfDirectParsed'] ?? false),
            'initialImportRows' => $overrides['importRows'] ?? null,
            'pageError' => $overrides['error'] ?? null,
        ]);
    }

    private function isPdfUpload(?UploadedFile $file): bool
    {
        if (! $file) {
            return false;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        return $extension === 'pdf' || str_contains($mime, 'pdf');
    }

    public function previewImport(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'json' => ['required', 'string'],
        ]);

        try {
            $rows = $this->importService->parseJson($validated['json']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.questions.create', array_filter([
                'syllabus_topic_id' => $validated['syllabus_topic_id'],
                'syllabus_chapter_id' => SyllabusTopic::query()
                    ->whereKey($validated['syllabus_topic_id'])
                    ->value('syllabus_chapter_id'),
                'mode' => $request->string('mode')->toString() ?: null,
            ]))
            ->with('import_rows', $rows);
    }

    public function storeBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_topic_id' => ['required', 'exists:syllabus_topics,id'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*.question_text' => ['required', 'string'],
            'rows.*.explanation' => ['nullable', 'string'],
            'rows.*.difficulty' => ['nullable', 'string', 'max:20'],
            'rows.*.options' => ['required', 'array', 'min:2'],
            'rows.*.options.*.option_text' => ['required', 'string'],
            'rows.*.options.*.is_correct' => ['boolean'],
        ]);

        $topic = SyllabusTopic::findOrFail($validated['syllabus_topic_id']);

        $saved = $this->importService->saveRows(
            $topic,
            $validated['rows'],
            $request->user()->id,
            Question::SOURCE_AI,
        );

        return redirect()
            ->route('admin.questions.topics.show', $topic->id)
            ->with('success', count($saved).' question(s) saved to the bank.');
    }

    public function edit(Question $question): Response
    {
        $question->load(['topic.chapter.syllabusVersion.gradeLevel', 'options']);

        return Inertia::render('Admin/Questions/Edit', [
            'question' => $question,
        ]);
    }

    public function update(Request $request, Question $question): RedirectResponse
    {
        $validated = $request->validate([
            'question_text' => ['required', 'string'],
            'explanation' => ['nullable', 'string'],
            'difficulty' => ['nullable', 'string', 'max:20'],
            'options' => ['required', 'array', 'min:2'],
            'options.*.option_text' => ['required', 'string'],
            'options.*.is_correct' => ['boolean'],
        ]);

        $this->importService->syncQuestion($question, $validated);

        return redirect()
            ->route('admin.questions.topics.show', $question->syllabus_topic_id)
            ->with('success', 'Question updated.');
    }

    public function destroy(Question $question): RedirectResponse
    {
        $topicId = $question->syllabus_topic_id;
        $question->delete();

        return redirect()
            ->route('admin.questions.topics.show', $topicId)
            ->with('success', 'Question deleted.');
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
}
