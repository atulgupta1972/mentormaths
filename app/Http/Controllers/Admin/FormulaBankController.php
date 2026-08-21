<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\Question;
use App\Models\SyllabusChapter;
use App\Models\SyllabusTopic;
use App\Models\Worksheet;
use App\Services\FormulaBankService;
use App\Support\PracticeSetScope;
use App\Support\QuestionBankPurpose;
use App\Support\WorksheetPurpose;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FormulaBankController extends Controller
{
    public function __construct(private FormulaBankService $formulaBank) {}

    public function index(Request $request): Response
    {
        $boards = Board::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name']);

        $boardId = $request->integer('board_id') ?: $boards->first()?->id;
        $board = $boardId ? $boards->firstWhere('id', $boardId) : null;
        $year = AcademicYear::active();
        $matrix = $board ? $this->formulaBank->matrixForBoard($board, $year) : null;
        $gradeId = $request->integer('grade_id') ?: null;

        if ($matrix && $gradeId && ! collect($matrix['grades'])->contains('id', $gradeId)) {
            $gradeId = null;
        }

        if ($matrix && ! $gradeId) {
            $gradeId = $matrix['grades'][0]['id'] ?? null;
        }

        return Inertia::render('Admin/FormulaBank/Index', [
            'boards' => $boards,
            'selectedBoardId' => $board?->id,
            'selectedGradeId' => $gradeId,
            'activeYear' => $year?->only(['id', 'name']),
            'matrix' => $matrix,
        ]);
    }

    public function classShow(Request $request, GradeLevel $grade): Response
    {
        $boards = Board::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);
        $boardId = $request->integer('board_id') ?: $boards->first()?->id;
        $board = $boards->firstWhere('id', $boardId);
        abort_unless($board, 404);

        return Inertia::render('Admin/FormulaBank/ClassShow', [
            'grade' => $grade->only(['id', 'name', 'sort_order']),
            'board' => $board->only(['id', 'code', 'name']),
            'boards' => $boards,
            'activeYear' => AcademicYear::active()?->only(['id', 'name']),
            'chapters' => $this->formulaBank->chaptersForClass($grade, $board),
        ]);
    }

    public function chapterShow(SyllabusChapter $chapter): Response
    {
        $chapter->load([
            'syllabusVersion.gradeLevel',
            'syllabusVersion.board',
            'topics' => fn ($q) => $q->orderBy('sort_order'),
        ]);

        $grade = $chapter->syllabusVersion?->gradeLevel;
        $board = $chapter->syllabusVersion?->board;
        abort_unless($grade && $board, 404);

        $topics = $chapter->topics->map(function (SyllabusTopic $topic) {
            return [
                'id' => $topic->id,
                'name' => $topic->name,
                'formulas_count' => Question::query()
                    ->where('syllabus_topic_id', $topic->id)
                    ->where('bank_purpose', QuestionBankPurpose::FORMULA)
                    ->count(),
                'sets_count' => Worksheet::query()
                    ->where('purpose', WorksheetPurpose::FORMULA)
                    ->where('scope', PracticeSetScope::TOPIC)
                    ->where('syllabus_topic_id', $topic->id)
                    ->count(),
            ];
        })->values()->all();

        return Inertia::render('Admin/FormulaBank/ChapterShow', [
            'chapter' => [
                'id' => $chapter->id,
                'name' => $chapter->name,
                'chapter_number' => $chapter->chapter_number,
            ],
            'grade' => $grade->only(['id', 'name', 'sort_order']),
            'board' => $board->only(['id', 'code', 'name']),
            'topics' => $topics,
            'formulas_count' => collect($topics)->sum('formulas_count'),
            'sets_count' => Worksheet::query()
                ->where('purpose', WorksheetPurpose::FORMULA)
                ->where(function ($q) use ($chapter, $topics) {
                    $topicIds = collect($topics)->pluck('id');
                    $q->where(function ($inner) use ($chapter) {
                        $inner->where('scope', PracticeSetScope::CHAPTER)
                            ->where('syllabus_chapter_id', $chapter->id);
                    })->orWhere(function ($inner) use ($topicIds) {
                        $inner->where('scope', PracticeSetScope::TOPIC)
                            ->whereIn('syllabus_topic_id', $topicIds);
                    });
                })
                ->count(),
            'topic_sets_count' => collect($topics)->sum('sets_count'),
            'cards' => $this->formulaBank->chapterCards($chapter),
            'cursorPrompt' => session('formula_bank_chapter_prompt'),
            'promptDefaults' => [
                'total' => (int) session('formula_bank_chapter_prompt_total', 12),
                'focus' => (string) session('formula_bank_chapter_prompt_focus', ''),
                'style' => (string) session('formula_bank_chapter_prompt_style', 'mixed'),
                'topic_ids' => session('formula_bank_chapter_prompt_topic_ids', collect($topics)->pluck('id')->all()),
            ],
        ]);
    }

    public function topicShow(SyllabusTopic $topic): Response
    {
        return Inertia::render('Admin/FormulaBank/TopicShow', [
            'topic' => $this->formulaBank->topicDetail($topic),
            'sampleJson' => $this->sampleJson(),
            'cursorPrompt' => session('formula_bank_topic_prompt'),
            'promptDefaults' => [
                'total' => (int) session('formula_bank_topic_prompt_total', 8),
                'focus' => (string) session('formula_bank_topic_prompt_focus', ''),
                'style' => (string) session('formula_bank_topic_prompt_style', 'mixed'),
            ],
        ]);
    }

    public function setShow(Request $request, Worksheet $worksheet): Response
    {
        abort_unless($worksheet->isFormula(), 404);

        $isAdmin = (bool) $request->user()?->isAdmin();

        if (! $isAdmin && $worksheet->status !== Worksheet::STATUS_PUBLISHED) {
            abort(403, 'This formula set is not available for preview.');
        }

        return Inertia::render('Admin/FormulaBank/SetShow', [
            'set' => $this->formulaBank->setDetail($worksheet, includeQuestions: $isAdmin),
            'sampleJson' => $isAdmin ? $this->sampleJson() : '',
            'canViewQuestions' => $isAdmin,
        ]);
    }

    public function storeSet(Request $request, SyllabusTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $set = $this->formulaBank->createSet($topic, $request->user(), $validated['title'] ?? null);

        return redirect()
            ->route('admin.formula-bank.sets.show', $set)
            ->with('success', 'Formula set '.$set->set_number.' created. Add formula / concept MCQs next.');
    }

    public function topicPrompt(Request $request, SyllabusTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'total' => ['required', 'integer', 'min:1', 'max:40'],
            'focus' => ['nullable', 'string', 'max:4000'],
            'style' => ['nullable', 'string', 'in:mixed,formula_recall,concept,true_false'],
        ]);

        $prompt = $this->formulaBank->cursorPromptForTopic($topic, [
            'total' => $validated['total'],
            'focus' => $validated['focus'] ?? '',
            'style' => $validated['style'] ?? 'mixed',
        ]);

        return back()->with([
            'success' => 'Cursor prompt ready — copy and paste into Cursor chat.',
            'formula_bank_topic_prompt' => $prompt,
            'formula_bank_topic_prompt_total' => $validated['total'],
            'formula_bank_topic_prompt_focus' => $validated['focus'] ?? '',
            'formula_bank_topic_prompt_style' => $validated['style'] ?? 'mixed',
        ]);
    }

    public function chapterPrompt(Request $request, SyllabusChapter $chapter): RedirectResponse
    {
        $validated = $request->validate([
            'total' => ['required', 'integer', 'min:1', 'max:60'],
            'focus' => ['nullable', 'string', 'max:4000'],
            'style' => ['nullable', 'string', 'in:mixed,formula_recall,concept,true_false'],
            'topic_ids' => ['nullable', 'array'],
            'topic_ids.*' => ['integer', 'exists:syllabus_topics,id'],
        ]);

        try {
            $prompt = $this->formulaBank->cursorPromptForChapter($chapter, [
                'total' => $validated['total'],
                'focus' => $validated['focus'] ?? '',
                'style' => $validated['style'] ?? 'mixed',
                'topic_ids' => $validated['topic_ids'] ?? [],
            ]);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with([
            'success' => 'Cursor prompt ready — copy and paste into Cursor chat.',
            'formula_bank_chapter_prompt' => $prompt,
            'formula_bank_chapter_prompt_total' => $validated['total'],
            'formula_bank_chapter_prompt_focus' => $validated['focus'] ?? '',
            'formula_bank_chapter_prompt_style' => $validated['style'] ?? 'mixed',
            'formula_bank_chapter_prompt_topic_ids' => $validated['topic_ids'] ?? [],
        ]);
    }

    public function destroyCard(Question $question): RedirectResponse
    {
        try {
            $this->formulaBank->deleteCard($question);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Formula / concept card deleted.');
    }

    public function importToChapter(Request $request, SyllabusChapter $chapter): RedirectResponse
    {
        $validated = $request->validate([
            'json' => ['required', 'string'],
            'create_sets' => ['nullable', 'boolean'],
        ]);

        try {
            $result = $this->formulaBank->importChapterJson(
                $chapter,
                $validated['json'],
                $request->user(),
                $request->boolean('create_sets', true),
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Imported {$result['created']} formula / concept cards";
        if ($result['sets_created'] > 0 && $result['set']) {
            $message .= " into one chapter set ({$result['set']->set_code})";
        } elseif (($result['set'] ?? null) && $result['created'] > 0) {
            $message .= " into {$result['set']->set_code}";
        }
        $message .= '.';

        return back()->with('success', $message);
    }

    public function consolidateChapter(Request $request, SyllabusChapter $chapter): RedirectResponse
    {
        try {
            $result = $this->formulaBank->consolidateChapterIntoOneSet($chapter, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $removed = $result['sets_removed'];
        $message = "Packed {$result['question_count']} formula cards into {$result['set']->set_code}";
        if ($removed > 0) {
            $message .= " (removed {$removed} smaller set".($removed === 1 ? '' : 's').')';
        }
        $message .= '.';

        return back()->with('success', $message);
    }

    public function importToTopic(Request $request, SyllabusTopic $topic): RedirectResponse
    {
        $validated = $request->validate([
            'json' => ['required', 'string'],
            'create_set' => ['nullable', 'boolean'],
            'worksheet_id' => ['nullable', 'integer', 'exists:worksheets,id'],
        ]);

        $set = null;
        if (! empty($validated['worksheet_id'])) {
            $set = Worksheet::query()->findOrFail($validated['worksheet_id']);
        } elseif ($request->boolean('create_set')) {
            $set = $this->formulaBank->createSet($topic, $request->user());
        }

        try {
            $result = $this->formulaBank->importJson(
                $topic,
                $validated['json'],
                $request->user(),
                $set,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $message = "Imported {$result['created']} formula / concept cards.";
        if ($result['set']) {
            return redirect()
                ->route('admin.formula-bank.sets.show', $result['set'])
                ->with('success', $message.' Attached to '.$result['set']->set_code.'.');
        }

        return back()->with('success', $message.' Package them into a set when ready.');
    }

    public function importToSet(Request $request, Worksheet $worksheet): RedirectResponse
    {
        abort_unless($worksheet->isFormula(), 404);
        $worksheet->loadMissing('topic');
        abort_unless($worksheet->topic, 404);

        $validated = $request->validate([
            'json' => ['required', 'string'],
        ]);

        try {
            $result = $this->formulaBank->importJson(
                $worksheet->topic,
                $validated['json'],
                $request->user(),
                $worksheet,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Imported {$result['created']} formula / concept cards into this set.");
    }

    public function packageTopic(Request $request, SyllabusTopic $topic): RedirectResponse
    {
        try {
            $set = $this->formulaBank->packageUnpacked($topic, $request->user());
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.formula-bank.sets.show', $set)
            ->with('success', 'Packaged unpacked cards into '.$set->set_code.'.');
    }

    private function sampleJson(): string
    {
        return json_encode([
            'questions' => [
                [
                    'question' => 'Which identity is correct for (a + b)²?',
                    'options' => [
                        'a² + b²',
                        'a² + 2ab + b²',
                        'a² − 2ab + b²',
                        '(a + b)(a − b)',
                    ],
                    'correct_index' => 1,
                    'explanation' => '(a + b)² = a² + 2ab + b²',
                ],
                [
                    'question' => 'Area of a rectangle with length l and breadth b is:',
                    'options' => ['2(l + b)', 'l × b', 'l + b', 'πl²'],
                    'correct_index' => 1,
                    'explanation' => 'Area = length × breadth',
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
