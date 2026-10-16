<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Services\AdminGradeContext;
use App\Services\PracticeSetService;
use App\Services\QuestionZipImportService;
use App\Services\WrittenSheetService;
use App\Support\PracticeSetMasterProfile;
use App\Support\QuestionBankPurpose;
use App\Support\SampleQuestionPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PackUploadController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private QuestionZipImportService $zipImportService,
        private PracticeSetService $practiceSetService,
        private WrittenSheetService $writtenSheetService,
    ) {}

    public function create(Request $request): Response
    {
        $grade = $this->gradeContext->resolve($request);
        $chapterId = $request->integer('syllabus_chapter_id') ?: null;
        $topicId = $request->integer('syllabus_topic_id') ?: null;

        if ($topicId && ! $chapterId) {
            $chapterId = SyllabusTopic::query()->whereKey($topicId)->value('syllabus_chapter_id');
        }

        $chapters = $this->chapterOptions($grade?->id);
        $samplePackKey = (string) $request->query('sample_pack', '');

        return Inertia::render('Admin/Questions/PackUpload', [
            'chapters' => $chapters,
            'chapterTopics' => $chapterId ? $this->topicsForChapter((int) $chapterId) : [],
            'topicsByChapter' => $chapters->mapWithKeys(fn ($chapter) => [
                $chapter['id'] => $this->topicsForChapter((int) $chapter['id']),
            ])->all(),
            'selectedChapterId' => $chapterId,
            'selectedTopicId' => $topicId,
            'selectedGrade' => $grade?->only(['id', 'name']),
            'masterProfiles' => PracticeSetMasterProfile::options(),
            'samplePacks' => SampleQuestionPack::options(),
            'selectedSamplePack' => SampleQuestionPack::find($samplePackKey)['key'] ?? '',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $sampleKeys = SampleQuestionPack::keys();

        $validated = $request->validate([
            'pack' => ['nullable', 'required_without:sample_pack', 'file', 'mimes:zip', 'max:51200'],
            'sample_pack' => [
                'nullable',
                'required_without:pack',
                'string',
                $sampleKeys === [] ? 'prohibited' : Rule::in($sampleKeys),
            ],
            'syllabus_chapter_id' => ['required', 'exists:syllabus_chapters,id'],
            'syllabus_topic_id' => ['nullable', 'exists:syllabus_topics,id'],
            'output_type' => ['required', 'in:mcq,fill_in_blank,written'],
            'master_profile' => ['required', 'in:'.implode(',', PracticeSetMasterProfile::keys())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'pack.required_without' => 'Choose a ready pack from the dropdown, or upload a .zip.',
            'sample_pack.required_without' => 'Choose a ready pack from the dropdown, or upload a .zip.',
            'pack.mimes' => 'Upload a .zip file.',
            'master_profile.required' => 'Choose Learner, Achiever, or Expert.',
            'output_type.required' => 'Choose MCQ, fill-in-blank, or written PDF.',
        ]);

        $chapter = SyllabusChapter::query()->with('topics')->findOrFail($validated['syllabus_chapter_id']);
        $topic = null;

        if (! empty($validated['syllabus_topic_id'])) {
            $topic = $chapter->topics->firstWhere('id', (int) $validated['syllabus_topic_id']);

            if (! $topic) {
                return back()->with('error', 'Selected topic does not belong to this chapter.');
            }
        }

        // Ready packs use teaching topic labels that won't match syllabus names —
        // pin them to the chosen topic (or the chapter's first topic).
        if ($topic === null && filled($validated['sample_pack'] ?? null)) {
            $topic = $chapter->topics->sortBy('sort_order')->values()->first();
            if (! $topic) {
                return back()->with('error', 'Add at least one topic in this chapter before loading a ready pack.');
            }
        }

        $tier = $validated['master_profile'];
        $outputType = $validated['output_type'];
        $tierLabel = PracticeSetMasterProfile::profile($tier)['label'] ?? PracticeSetMasterProfile::options()[0]['label'] ?? $tier;

        try {
            $packFile = $this->resolvePackFile($request, $validated['sample_pack'] ?? null);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        try {
            $result = $this->zipImportService->importPack(
                $packFile,
                $request->user(),
                $topic,
                $topic ? null : $chapter,
                QuestionBankPurpose::PRACTICE_SET,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $saved = $result['saved'] ?? [];
        $questionIds = collect($saved)->pluck('id')->values()->all();

        if ($questionIds === []) {
            $this->forgetPersistedPdf($result['sheet_pdf_path'] ?? null);

            return back()->with('error', 'No questions could be imported from the zip.');
        }

        if ($outputType === 'mcq' || $outputType === 'fill_in_blank') {
            $wantFill = $outputType === 'fill_in_blank';
            $mismatch = collect($saved)->first(
                fn (Question $question) => $wantFill ? ! $question->isFillInBlank() : ! $question->isMcq()
            );
            if ($mismatch) {
                $this->forgetPersistedPdf($result['sheet_pdf_path'] ?? null);

                return back()->with(
                    'error',
                    $wantFill
                        ? 'This zip is not fill-in-blank JSON. For fill-in-blank sets, each sum needs correct_answer (and answer_format).'
                        : 'This zip is not MCQ JSON. For MCQ sets, each sum needs options and correct_index.',
                );
            }

            try {
                $worksheet = $topic
                    ? $this->practiceSetService->createTopicPracticeSet(
                        $topic,
                        $questionIds,
                        $request->user()->id,
                        $tier,
                        fillInBlank: $wantFill,
                    )
                    : $this->practiceSetService->createChapterPracticeSet(
                        $chapter,
                        $questionIds,
                        $request->user()->id,
                        $tier,
                        fillInBlank: $wantFill,
                    );
            } catch (\InvalidArgumentException $e) {
                $this->forgetPersistedPdf($result['sheet_pdf_path'] ?? null);

                return back()->with('error', $e->getMessage());
            }

            $this->forgetPersistedPdf($result['sheet_pdf_path'] ?? null);

            $formatLabel = $wantFill ? 'fill-in-blank' : 'MCQ';
            $message = count($questionIds).' sum(s) uploaded into '.$worksheet->set_code
                ." ({$tierLabel} {$formatLabel}).";
            if (($result['diagram_count'] ?? 0) > 0) {
                $message .= " {$result['diagram_count']} diagram(s) attached.";
            }
            if (! empty($validated['sample_pack'])) {
                $sampleLabel = SampleQuestionPack::find((string) $validated['sample_pack'])['label'] ?? 'Ready pack';
                $message = $sampleLabel.': '.$message;
            }

            return redirect()
                ->route('admin.questions.sets.show', $worksheet)
                ->with('success', $message);
        }

        $usedUploadedPdf = false;

        try {
            $worksheet = $topic
                ? $this->writtenSheetService->createFromTopic(
                    $topic,
                    $questionIds,
                    $request->user(),
                    $validated['notes'] ?? null,
                    $tier,
                )
                : $this->writtenSheetService->createChapterTest(
                    $chapter,
                    $questionIds,
                    $request->user(),
                    $validated['notes'] ?? null,
                    $tier,
                );

            $pdfPath = $result['sheet_pdf_path'] ?? null;
            if (is_string($pdfPath) && is_file($pdfPath)) {
                $this->writtenSheetService->replacePdf($worksheet, $pdfPath);
                @unlink($pdfPath);
                $usedUploadedPdf = true;
            } else {
                $this->writtenSheetService->generatePdf($worksheet);
            }
        } catch (\InvalidArgumentException $e) {
            $this->forgetPersistedPdf($result['sheet_pdf_path'] ?? null);

            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            $this->forgetPersistedPdf($result['sheet_pdf_path'] ?? null);

            return back()->with('error', 'Sums imported, but the written PDF could not be prepared. '.$e->getMessage());
        }

        $message = count($questionIds).' sum(s) uploaded into written sheet '.$worksheet->set_code
            ." ({$tierLabel}).";
        if (($result['diagram_count'] ?? 0) > 0) {
            $message .= " {$result['diagram_count']} diagram(s) attached.";
        }
        $message .= $usedUploadedPdf
            ? ' Your PDF from the zip was attached.'
            : ' Worksheet PDF generated for review.';

        return redirect()
            ->route('admin.written-sheets.show', $worksheet)
            ->with(($result['missing_diagram_count'] ?? 0) > 0 ? 'warning' : 'success', $message);
    }

    private function forgetPersistedPdf(?string $path): void
    {
        if (is_string($path) && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function resolvePackFile(Request $request, ?string $samplePackKey): UploadedFile
    {
        if ($request->hasFile('pack')) {
            return $request->file('pack');
        }

        $key = trim((string) $samplePackKey);
        if ($key === '') {
            throw new \InvalidArgumentException('Choose a ready pack from the dropdown, or upload a .zip.');
        }

        $path = SampleQuestionPack::absolutePath($key);
        if ($path === null) {
            throw new \InvalidArgumentException('That ready pack is missing on the server. Pull latest samples and try again.');
        }

        return new UploadedFile($path, basename($path), 'application/zip', null, true);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{id: int, label: string}>
     */
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

    /**
     * @return list<array{id: int, name: string, questions_count: int}>
     */
    private function topicsForChapter(int $chapterId): array
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
            ])
            ->values()
            ->all();
    }
}
