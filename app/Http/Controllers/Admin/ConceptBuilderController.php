<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\TextbookChapter;
use App\Services\AdminGradeContext;
use App\Support\ConceptPathStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConceptBuilderController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
    ) {}

    public function index(Request $request): Response
    {
        $gradeLevel = $this->gradeContext->resolve($request);
        $uploaderMode = $request->routeIs('content.*');
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();

        $chapters = [];

        if ($gradeLevel && $activeYear && $maths) {
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

                            return [
                                'id' => $upload->id,
                                'book_name' => $upload->textbook?->name,
                                'book_code' => $upload->textbook?->code,
                                'has_pdf' => $hasPdf,
                                'status_label' => $upload->statusLabel(),
                                'concept_path_status' => $upload->concept_path_status,
                                'concept_path_status_label' => ConceptPathStatus::label($upload->concept_path_status),
                                'concept_path_url' => $hasPdf
                                    ? ($uploaderMode
                                        ? route('content.textbooks.concept-path', $upload)
                                        : route('admin.textbooks.concept-path', $upload))
                                    : null,
                                'upload_url' => $uploaderMode
                                    ? route('content.textbooks.show', $upload)
                                    : route('admin.textbooks.show', $upload),
                            ];
                        })
                        ->values()
                        ->all();

                    $readyUpload = collect($uploads)->firstWhere('has_pdf', true);
                    $pendingUpload = collect($uploads)->firstWhere('has_pdf', false);
                    $createUrl = $uploaderMode ? null : route('admin.textbooks.create');

                    $chapters[] = [
                        'syllabus_chapter_id' => $syllabusChapter->id,
                        'board_code' => $version->board?->code,
                        'board_name' => $version->board?->name,
                        'label' => $this->chapterLabel($syllabusChapter),
                        'chapter_number' => $syllabusChapter->chapter_number,
                        'name' => $syllabusChapter->name,
                        'uploads' => $uploads,
                        'has_pdf' => $readyUpload !== null,
                        'primary_action_url' => $readyUpload['concept_path_url']
                            ?? ($pendingUpload['upload_url'] ?? $createUrl),
                        'primary_action_label' => $readyUpload
                            ? 'Build concepts'
                            : ($pendingUpload ? 'Open chapter · upload PDF' : 'Upload chapter PDF first'),
                        'needs_upload' => $readyUpload === null,
                    ];
                }
            }
        }

        return Inertia::render('Admin/ConceptBuilder/Index', [
            'uploaderMode' => $uploaderMode,
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'chapters' => $chapters,
            'createUrl' => $uploaderMode ? null : route('admin.textbooks.create'),
        ]);
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
