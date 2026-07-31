<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExtractTextbookChapterJob;
use App\Models\AcademicYear;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Services\AdminGradeContext;
use App\Services\TextbookChapterPublishService;
use App\Support\UploadedFileDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TextbookController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private TextbookChapterPublishService $publishService,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        $chapters = TextbookChapter::query()
            ->with([
                'textbook:id,name,code,grade_level_id',
                'textbook.gradeLevel:id,name',
                'syllabusChapter:id,name,chapter_number',
                'mcqWorksheet:id,set_code',
                'writtenWorksheet:id,set_code',
            ])
            ->when($gradeLevel, fn ($q) => $q->whereHas(
                'textbook',
                fn ($inner) => $inner->where('grade_level_id', $gradeLevel->id),
            ))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TextbookChapter $chapter) => [
                'id' => $chapter->id,
                'book_name' => $chapter->textbook?->name,
                'book_code' => $chapter->textbook?->code,
                'grade_name' => $chapter->textbook?->gradeLevel?->name,
                'chapter_number' => $chapter->chapter_number,
                'title' => $chapter->title,
                'status' => $chapter->status,
                'status_label' => $chapter->statusLabel(),
                'items_count' => count($chapter->extraction_items ?? []),
                'mcq_set_code' => $chapter->mcqWorksheet?->set_code,
                'written_set_code' => $chapter->writtenWorksheet?->set_code,
                'published_at' => $chapter->published_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return Inertia::render('Admin/Textbooks/Index', [
            'chapters' => $chapters,
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
        ]);
    }

    public function create(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $activeYear = AcademicYear::active();
        $chapters = [];
        $books = [];

        if ($gradeLevel && $activeYear) {
            $syllabus = SyllabusVersion::query()
                ->with(['chapters' => fn ($q) => $q->orderBy('sort_order')])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->first();

            $chapters = $syllabus?->chapters->map(fn (SyllabusChapter $chapter) => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
                'label' => self::chapterLabel($chapter),
            ])->values()->all() ?? [];

            $books = Textbook::query()
                ->where('grade_level_id', $gradeLevel->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
                ->all();
        }

        return Inertia::render('Admin/Textbooks/Create', [
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'syllabusChapters' => $chapters,
            'books' => $books,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);

        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $uploadedPdf = $request->file('pdf');
        if ($uploadedPdf) {
            UploadedFileDiagnostics::assertValid($uploadedPdf, 'pdf');
        }

        $validated = $request->validate([
            'book_name' => ['required', 'string', 'max:255'],
            'book_code' => ['required', 'string', 'max:32', 'alpha_dash'],
            'syllabus_chapter_id' => ['required', 'integer', Rule::exists('syllabus_chapters', 'id')],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ], [
            'pdf.required' => 'Choose a chapter PDF file.',
            'pdf.mimes' => 'Only PDF files are allowed.',
            'pdf.max' => 'Each chapter PDF must be under 50 MB.',
            'pdf.uploaded' => 'The PDF is too large for the server upload limit. Set PHP upload_max_filesize and post_max_size to at least 20M on the server.',
        ]);

        $syllabusChapter = SyllabusChapter::query()->findOrFail($validated['syllabus_chapter_id']);
        $chapterNumber = $syllabusChapter->numericChapterNumber();

        $textbook = Textbook::query()->firstOrCreate(
            [
                'grade_level_id' => $gradeLevel->id,
                'code' => strtolower($validated['book_code']),
            ],
            [
                'name' => $validated['book_name'],
                'created_by' => $request->user()->id,
            ],
        );

        if ($textbook->name !== $validated['book_name']) {
            $textbook->update(['name' => $validated['book_name']]);
        }

        $existing = TextbookChapter::query()
            ->where('textbook_id', $textbook->id)
            ->where('syllabus_chapter_id', $syllabusChapter->id)
            ->first();

        if ($existing) {
            return redirect()
                ->route('admin.textbooks.show', $existing)
                ->with('error', 'This chapter is already uploaded for this book. Open it to re-extract or publish.');
        }

        $directory = 'textbooks/'.$textbook->id.'/chapters/'.$chapterNumber;
        $pdfPath = $request->file('pdf')->store($directory, 'public');

        $chapter = TextbookChapter::create([
            'textbook_id' => $textbook->id,
            'syllabus_chapter_id' => $syllabusChapter->id,
            'chapter_number' => $chapterNumber,
            'title' => $syllabusChapter->name,
            'pdf_path' => $pdfPath,
            'status' => TextbookChapter::STATUS_DRAFT,
            'created_by' => $request->user()->id,
        ]);

        ExtractTextbookChapterJob::dispatch($chapter->id);

        return redirect()
            ->route('admin.textbooks.index')
            ->with('success', 'Chapter PDF uploaded. AI extraction is running in the background — we will email you when it is ready to review (usually 5–10 minutes).');
    }

    public function show(TextbookChapter $textbookChapter): Response
    {
        $textbookChapter->load([
            'textbook.gradeLevel',
            'syllabusChapter',
            'mcqWorksheet',
            'writtenWorksheet',
        ]);

        return Inertia::render('Admin/Textbooks/Show', [
            'chapter' => [
                'id' => $textbookChapter->id,
                'status' => $textbookChapter->status,
                'status_label' => $textbookChapter->statusLabel(),
                'chapter_number' => $textbookChapter->chapter_number,
                'title' => $textbookChapter->title,
                'pdf_url' => $textbookChapter->pdfUrl(),
                'extraction_error' => $textbookChapter->extraction_error,
                'extracted_at' => $textbookChapter->extracted_at?->toDateTimeString(),
                'published_at' => $textbookChapter->published_at?->toDateTimeString(),
                'book' => [
                    'name' => $textbookChapter->textbook?->name,
                    'code' => $textbookChapter->textbook?->code,
                    'grade_name' => $textbookChapter->textbook?->gradeLevel?->name,
                ],
                'items' => $textbookChapter->extraction_items ?? [],
                'mcq_worksheet_id' => $textbookChapter->mcq_worksheet_id,
                'written_worksheet_id' => $textbookChapter->written_worksheet_id,
                'mcq_set_code' => $textbookChapter->mcqWorksheet?->set_code,
                'written_set_code' => $textbookChapter->writtenWorksheet?->set_code,
            ],
        ]);
    }

    public function updateDraft(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        abort_unless(in_array($textbookChapter->status, [
            TextbookChapter::STATUS_REVIEW,
            TextbookChapter::STATUS_PUBLISHED,
            TextbookChapter::STATUS_FAILED,
        ], true), 422);

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
        ]);

        $textbookChapter->update([
            'extraction_items' => $validated['items'],
            'status' => TextbookChapter::STATUS_REVIEW,
        ]);

        return back()->with('success', 'Draft saved.');
    }

    public function publish(Request $request, TextbookChapter $textbookChapter): RedirectResponse
    {
        $items = $textbookChapter->extraction_items ?? [];

        if ($request->has('items')) {
            $validated = $request->validate([
                'items' => ['required', 'array', 'min:1'],
            ]);
            $items = $validated['items'];
        }

        $this->publishService->publish($textbookChapter, $items, $request->user());

        return redirect()
            ->route('admin.textbooks.show', $textbookChapter)
            ->with('success', 'Published — MCQ and written sets are ready to assign.');
    }

    public function reextract(TextbookChapter $textbookChapter): RedirectResponse
    {
        $textbookChapter->update([
            'status' => TextbookChapter::STATUS_DRAFT,
            'extraction_error' => null,
        ]);

        ExtractTextbookChapterJob::dispatch($textbookChapter->id);

        return redirect()
            ->route('admin.textbooks.index')
            ->with('success', 'Re-extraction started in the background — we will email you when it is ready to review.');
    }

    public function download(TextbookChapter $textbookChapter)
    {
        abort_unless(Storage::disk('public')->exists($textbookChapter->pdf_path), 404);

        return Storage::disk('public')->download(
            $textbookChapter->pdf_path,
            ($textbookChapter->textbook?->code ?: 'textbook').'-ch'.$textbookChapter->chapter_number.'.pdf',
        );
    }

    private static function chapterLabel(SyllabusChapter $chapter): string
    {
        $name = trim($chapter->name);

        if (preg_match('/^Ch\s*\d+/i', $name)) {
            return $name;
        }

        $number = preg_replace('/^Ch\s*/i', '', trim((string) $chapter->chapter_number));
        $number = ltrim($number, '0') ?: $number;

        return "Ch {$number} — {$name}";
    }
}
