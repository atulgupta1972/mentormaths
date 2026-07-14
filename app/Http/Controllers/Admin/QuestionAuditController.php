<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\QuestionBlankAnswer;
use App\Models\QuestionSetAudit;
use App\Models\Subject;
use App\Models\SyllabusChapter;
use App\Models\SyllabusVersion;
use App\Models\Worksheet;
use App\Services\AdminGradeContext;
use App\Services\QuestionAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QuestionAuditController extends Controller
{
    public function __construct(
        private AdminGradeContext $gradeContext,
        private QuestionAuditService $auditService,
    ) {}

    public function index(Request $request): Response
    {
        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();
        $grades = $this->gradeContext->classLevels();

        $boardSections = Board::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(function (Board $board) use ($activeYear, $maths, $grades) {
                $classes = $grades
                    ->map(fn (GradeLevel $grade) => $this->classCardForBoard($grade, $board, $activeYear, $maths))
                    ->filter(fn (array $card) => $card['has_syllabus'])
                    ->values();

                return [
                    'id' => $board->id,
                    'code' => $board->code,
                    'name' => $board->name,
                    'classes' => $classes,
                ];
            })
            ->filter(fn (array $section) => $section['classes']->isNotEmpty())
            ->values();

        return Inertia::render('Admin/QuestionAudit/Index', [
            'boardSections' => $boardSections,
            'activeYear' => $activeYear?->only(['id', 'name']),
        ]);
    }

    public function chapters(Request $request, GradeLevel $gradeLevel): Response|RedirectResponse
    {
        if (! in_array($gradeLevel->sort_order, AdminGradeContext::CLASS_SORT_ORDERS, true)) {
            abort(404);
        }

        $this->gradeContext->persist($request, $gradeLevel->id);

        $boardId = $request->integer('board_id') ?: $this->gradeContext->resolveBoardId($request);
        if (! $boardId) {
            return redirect()
                ->route('admin.question-audit.index')
                ->with('warning', 'Choose a board and class to audit answers.');
        }

        $board = Board::query()->whereKey($boardId)->where('is_active', true)->first();
        if (! $board) {
            return redirect()
                ->route('admin.question-audit.index')
                ->with('warning', 'That board is not available. Please choose again.');
        }

        $this->gradeContext->persistBoard($request, $board->id);

        $activeYear = AcademicYear::active();
        $maths = Subject::query()->where('code', 'MATHS')->first();
        $syllabusVersion = null;
        $chapters = collect();

        if ($activeYear && $maths) {
            $syllabusVersion = SyllabusVersion::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $gradeLevel->id)
                ->where('subject_id', $maths->id)
                ->where('board_id', $board->id)
                ->first();

            if ($syllabusVersion) {
                $chapters = SyllabusChapter::query()
                    ->where('syllabus_version_id', $syllabusVersion->id)
                    ->orderBy('sort_order')
                    ->get()
                    ->map(function (SyllabusChapter $chapter) {
                        $summary = $this->auditService->chapterAuditSummary($chapter->id);

                        return [
                            'id' => $chapter->id,
                            'chapter_number' => $chapter->chapter_number,
                            'name' => $chapter->name,
                            ...$summary,
                        ];
                    });
            }
        }

        return Inertia::render('Admin/QuestionAudit/Chapters', [
            'gradeLevel' => $gradeLevel->only(['id', 'name']),
            'board' => $board->only(['id', 'code', 'name']),
            'activeYear' => $activeYear?->only(['id', 'name']),
            'syllabusVersion' => $syllabusVersion?->only(['id']),
            'chapters' => $chapters,
            'stats' => [
                'chapters_count' => $chapters->count(),
                'total_sets' => $chapters->sum('total_sets'),
                'not_audited' => $chapters->sum('not_audited'),
                'issues' => $chapters->sum('issues'),
            ],
        ]);
    }

    public function chapterSets(Request $request, SyllabusChapter $chapter): Response
    {
        $chapter->load([
            'syllabusVersion.board',
            'syllabusVersion.gradeLevel',
            'syllabusVersion.academicYear',
        ]);

        $gradeLevel = $chapter->syllabusVersion?->gradeLevel;
        $board = $chapter->syllabusVersion?->board;

        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        if ($board) {
            $this->gradeContext->persistBoard($request, $board->id);
        }

        $sets = $this->auditService->packagedWorksheetsForChapter($chapter->id)
            ->map(fn (Worksheet $worksheet) => $this->formatWorksheetCard($worksheet));

        return Inertia::render('Admin/QuestionAudit/ChapterSets', [
            'chapter' => [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
            ],
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'board' => $board?->only(['id', 'code', 'name']),
            'activeYear' => $chapter->syllabusVersion?->academicYear?->only(['id', 'name']),
            'summary' => $this->auditService->chapterAuditSummary($chapter->id),
            'sets' => $sets,
        ]);
    }

    public function show(Request $request, Worksheet $worksheet): Response
    {
        $worksheet->load([
            'topic.chapter.syllabusVersion.board',
            'topic.chapter.syllabusVersion.gradeLevel',
            'chapter.syllabusVersion.board',
            'chapter.syllabusVersion.gradeLevel',
            'latestAudit.auditor:id,name',
            'questions.options',
            'questions.blankAnswer',
        ]);
        $worksheet->loadCount('questions');

        $chapter = $worksheet->chapter ?? $worksheet->topic?->chapter;
        $gradeLevel = $chapter?->syllabusVersion?->gradeLevel;
        $board = $chapter?->syllabusVersion?->board;

        if ($gradeLevel) {
            $this->gradeContext->persist($request, $gradeLevel->id);
        }

        if ($board) {
            $this->gradeContext->persistBoard($request, $board->id);
        }

        $latestAudit = $worksheet->latestAudit;
        $questionsById = $worksheet->questions->keyBy('id');

        $findings = collect($latestAudit?->findings ?? [])
            ->map(function (array $finding) use ($questionsById, $worksheet) {
                $question = $questionsById->get($finding['question_id']);
                $correctOption = $question?->options->firstWhere('is_correct', true);
                $storedAnswer = $question?->blankAnswer?->correct_answer ?? $correctOption?->option_text;

                return array_merge($finding, [
                    'edit_url' => $this->editUrlForQuestion($question, $worksheet),
                    'can_inline_edit' => $question?->isFillInBlank() ?? false,
                    'answer_format' => $question?->blankAnswer?->answer_format,
                    'decimal_places' => $question?->blankAnswer?->decimal_places,
                    'stored_answer' => $storedAnswer,
                    'explanation' => $question?->explanation,
                    'method_hint' => $question?->method_hint,
                    'difficulty' => $question?->difficulty,
                ]);
            })
            ->values()
            ->all();

        return Inertia::render('Admin/QuestionAudit/Show', [
            'worksheet' => [
                'id' => $worksheet->id,
                'set_code' => $worksheet->set_code,
                'tier_label' => $worksheet->tier_label,
                'kind_label' => $worksheet->isChapterTest() ? 'Chapter test' : 'Practice set',
                'questions_count' => $worksheet->questions_count,
                'status' => $worksheet->status,
                'topic_name' => $worksheet->topic?->name,
            ],
            'chapter' => $chapter ? [
                'id' => $chapter->id,
                'chapter_number' => $chapter->chapter_number,
                'name' => $chapter->name,
            ] : null,
            'gradeLevel' => $gradeLevel?->only(['id', 'name']),
            'board' => $board?->only(['id', 'code', 'name']),
            'audit' => $latestAudit ? [
                'id' => $latestAudit->id,
                'status' => $latestAudit->status,
                'issue_count' => $latestAudit->issue_count,
                'audited_at' => $latestAudit->created_at?->toDateTimeString(),
                'audited_by' => $latestAudit->auditor?->name,
            ] : null,
            'findings' => $findings,
            'answerFormats' => QuestionBlankAnswer::formats(),
        ]);
    }

    public function run(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $result = $this->auditService->auditWorksheet($worksheet);
        $this->auditService->recordAudit($worksheet, $request->user(), $result);

        $message = $result['status'] === QuestionSetAudit::STATUS_CLEAN
            ? "Audit complete for {$worksheet->set_code}. No issues found."
            : "Audit complete for {$worksheet->set_code}. Found {$result['issue_count']} issue(s).";

        return redirect()
            ->route('admin.question-audit.worksheets.show', $worksheet)
            ->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatWorksheetCard(Worksheet $worksheet): array
    {
        $audit = $worksheet->latestAudit;

        return [
            'id' => $worksheet->id,
            'set_code' => $worksheet->set_code,
            'tier_label' => $worksheet->tier_label,
            'kind_label' => $worksheet->isChapterTest() ? 'Chapter test' : 'Practice set',
            'topic_name' => $worksheet->topic?->name,
            'questions_count' => $worksheet->questions_count,
            'status' => $worksheet->status,
            'audit_status' => $audit?->status ?? 'not_audited',
            'issue_count' => $audit?->issue_count ?? 0,
            'audited_at' => $audit?->created_at?->toDateTimeString(),
            'audited_by' => $audit?->auditor?->name,
        ];
    }

    private function editUrlForQuestion(?\App\Models\Question $question, Worksheet $worksheet): ?string
    {
        if (! $question) {
            return null;
        }

        if ($question->isFillInBlank()) {
            return route('admin.questions.set-code', ['code' => $worksheet->set_code]).'#question-'.$question->id;
        }

        return route('admin.questions.edit', $question);
    }

    private function classCardForBoard(
        GradeLevel $grade,
        Board $board,
        ?AcademicYear $activeYear,
        ?Subject $maths,
    ): array {
        $syllabus = null;
        $chaptersCount = 0;
        $setsCount = 0;
        $notAudited = 0;
        $issues = 0;

        if ($activeYear && $maths) {
            $syllabus = SyllabusVersion::query()
                ->where('academic_year_id', $activeYear->id)
                ->where('grade_level_id', $grade->id)
                ->where('subject_id', $maths->id)
                ->where('board_id', $board->id)
                ->first();

            if ($syllabus) {
                $chapterIds = SyllabusChapter::query()
                    ->where('syllabus_version_id', $syllabus->id)
                    ->pluck('id');

                $chaptersCount = $chapterIds->count();

                foreach ($chapterIds as $chapterId) {
                    $summary = $this->auditService->chapterAuditSummary($chapterId);
                    $setsCount += $summary['total_sets'];
                    $notAudited += $summary['not_audited'];
                    $issues += $summary['issues'];
                }
            }
        }

        return [
            'id' => $grade->id,
            'name' => $grade->name,
            'has_syllabus' => $syllabus !== null,
            'chapters_count' => $chaptersCount,
            'sets_count' => $setsCount,
            'not_audited' => $notAudited,
            'issues' => $issues,
        ];
    }
}
