<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Textbook;
use App\Models\TextbookChapter;
use App\Services\AdminGradeContext;
use App\Services\TextbookChapterBookService;
use App\Support\ConceptPathStatus;
use App\Support\UploadedFileDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ConceptBuilderController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private TextbookChapterBookService $bookService,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $uploaderMode = $request->routeIs('content.*');
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $chapters = [];
        $books = [];

        if ($gradeLevel && $activeYear && $maths) {
            $books = Textbook::query()
                ->where('grade_level_id', $gradeLevel->id)
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'board_id'])
                ->map(fn (Textbook $book) => [
                    'id' => $book->id,
                    'name' => $book->name,
                    'code' => $book->code,
                    'board_id' => $book->board_id,
                    'label' => "{$book->name} ({$book->code})",
                ])
                ->values()
                ->all();

            $versions = SyllabusVersion::query()
                ->with([
                    'board:id,code,name',
                    'chapters' => fn ($q) => $q->orderBy('sort_order')->orderBy('id'),
                ])
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('subject_id', $maths->id)
                ->orderBy('id')
                ->get();

            $syllabusChapterIds = $versions->flatMap(fn ($v) => $v->chapters->pluck('id'))->all();

            $textbookChapters = TextbookChapter::query()
                ->with(['textbook:id,name,code,grade_level_id', 'syllabusChapter:id,name,chapter_number'])
                ->whereIn('syllabus_chapter_id', $syllabusChapterIds ?: [-1])
                ->get()
                ->each(function (TextbookChapter $row) {
                    $row->syncDisplayFromSyllabus();
                })
                ->groupBy('syllabus_chapter_id');

            foreach ($versions as $version) {
                foreach ($version->chapters as $syllabusChapter) {
                    $uploads = ($textbookChapters->get($syllabusChapter->id) ?? collect())
                        ->map(function (TextbookChapter $upload) use ($uploaderMode) {
                            $hasPdf = filled($upload->pdf_path);
                            $isApproved = $upload->concept_path_status === ConceptPathStatus::APPROVED;
                            $cardCount = is_array($upload->concept_path_items['cards'] ?? null)
                                ? count(array_filter(
                                    $upload->concept_path_items['cards'],
                                    fn ($card) => is_array($card) && ($card['approved'] ?? true),
                                ))
                                : 0;

                            return [
                                'id' => $upload->id,
                                'book_name' => $upload->textbook?->name,
                                'book_code' => $upload->textbook?->code,
                                'textbook_id' => $upload->textbook_id,
                                'has_pdf' => $hasPdf,
                                'status_label' => $upload->statusLabel(),
                                'concept_path_status' => $upload->concept_path_status,
                                'concept_path_status_label' => ConceptPathStatus::label($upload->concept_path_status),
                                'concept_path_card_count' => $cardCount,
                                'is_approved' => $isApproved && $cardCount > 0,
                                'concept_path_url' => $hasPdf
                                    ? ($uploaderMode
                                        ? route('content.textbooks.concept-path', $upload)
                                        : route('admin.textbooks.concept-path', $upload))
                                    : null,
                                'run_url' => ($isApproved && $cardCount > 0)
                                    ? ($uploaderMode
                                        ? route('content.textbooks.concept-path.play', $upload)
                                        : route('admin.textbooks.concept-path.play', $upload))
                                    : null,
                                'upload_url' => $uploaderMode
                                    ? route('content.textbooks.show', $upload)
                                    : route('admin.textbooks.show', $upload),
                            ];
                        })
                        ->values()
                        ->all();

                    $readyUploads = collect($uploads)->where('has_pdf', true)->values();
                    $approvedUpload = collect($uploads)->firstWhere('is_approved', true);
                    $linkedTextbookIds = collect($uploads)->pluck('textbook_id')->filter()->values()->all();

                    $chapters[] = [
                        'syllabus_chapter_id' => $syllabusChapter->id,
                        'board_id' => $version->board_id,
                        'board_code' => $version->board?->code,
                        'board_name' => $version->board?->name,
                        'label' => $this->chapterLabel($syllabusChapter),
                        'chapter_number' => $syllabusChapter->chapter_number,
                        'name' => $syllabusChapter->name,
                        'uploads' => $uploads,
                        'has_pdf' => $readyUploads->isNotEmpty(),
                        'is_approved' => $approvedUpload !== null,
                        'run_url' => $approvedUpload['run_url'] ?? null,
                        'linked_textbook_ids' => $linkedTextbookIds,
                        'needs_upload' => $readyUploads->isEmpty(),
                    ];
                }
            }
        }

        return Inertia::render('Admin/ConceptBuilder/Index', [
            'uploaderMode' => $uploaderMode,
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'chapters' => $chapters,
            'books' => $books,
            'storeUrl' => $uploaderMode
                ? route('content.concept-builder.store')
                : route('admin.concept-builder.store'),
            'createUrl' => $uploaderMode ? null : route('admin.textbooks.create'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        abort_unless($gradeLevel, 422, 'Select a class from the top bar first.');

        $uploaderMode = $request->routeIs('content.*');
        $uploadedPdf = $request->file('pdf');
        if ($uploadedPdf) {
            UploadedFileDiagnostics::assertValid($uploadedPdf, 'pdf');
        }

        $validated = $request->validate([
            'syllabus_chapter_id' => ['required', 'integer', Rule::exists('syllabus_chapters', 'id')],
            'textbook_id' => ['nullable', 'integer', Rule::exists('textbooks', 'id')],
            'book_name' => ['required_without:textbook_id', 'nullable', 'string', 'max:255'],
            'book_code' => ['required_without:textbook_id', 'nullable', 'string', 'max:32', 'alpha_dash'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:51200'],
        ], [
            'pdf.required' => 'Choose a chapter PDF file.',
            'pdf.mimes' => 'Only PDF files are allowed.',
            'pdf.max' => 'Each chapter PDF must be under 50 MB.',
            'pdf.uploaded' => 'The PDF is too large for the server upload limit. Set PHP upload_max_filesize and post_max_size to at least 20M on the server.',
            'book_name.required_without' => 'Pick an existing book or enter a new book name.',
            'book_code.required_without' => 'Pick an existing book or enter a new book code.',
        ]);

        $syllabusChapter = SyllabusChapter::query()
            ->with('syllabusVersion')
            ->findOrFail($validated['syllabus_chapter_id']);

        if ((int) ($syllabusChapter->syllabusVersion?->grade_level_id ?? 0) !== (int) $gradeLevel->id) {
            return back()->with('error', 'That chapter is not for the selected class.');
        }

        try {
            $chapter = $this->bookService->ensureChapterPdfForSyllabus(
                $syllabusChapter,
                (int) $gradeLevel->id,
                $request->user(),
                $uploadedPdf,
                isset($validated['textbook_id']) ? (int) $validated['textbook_id'] : null,
                $validated['book_name'] ?? null,
                $validated['book_code'] ?? null,
                $uploaderMode,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $conceptPathUrl = $uploaderMode
            ? route('content.textbooks.concept-path', $chapter)
            : route('admin.textbooks.concept-path', $chapter);

        return redirect()
            ->to($conceptPathUrl)
            ->with('success', 'Chapter PDF saved for '.($chapter->textbook?->name ?? 'book').'. Continue with concept cards.');
    }

    private function chapterLabel(SyllabusChapter $chapter): string
    {
        $name = trim($chapter->name);

        if (preg_match('/^Ch\s*\d+/i', $name)) {
            return $name;
        }

        $number = preg_replace('/^Ch\s*/i', '', trim((string) $chapter->chapter_number));
        $number = ltrim((string) $number, '0') ?: $number;

        return $number !== '' ? "Ch {$number} — {$name}" : $name;
    }
}
