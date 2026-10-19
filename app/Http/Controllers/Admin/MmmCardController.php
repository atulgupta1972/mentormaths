<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\MmmCard;
use App\Services\MmmCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MmmCardController extends Controller
{
    public function __construct(private MmmCardService $mmmCards) {}

    public function index(): Response
    {
        $cards = MmmCard::query()
            ->withCount(['exercises', 'syllabusChapters'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MmmCard $card) => [
                'id' => $card->id,
                'slug' => $card->slug,
                'title' => $card->title,
                'heading' => $card->heading,
                'status' => $card->status,
                'accent' => $card->accent,
                'exercises_count' => $card->exercises_count,
                'chapters_count' => $card->syllabus_chapters_count,
            ]);

        return Inertia::render('Admin/MmmCards/Index', [
            'cards' => $cards,
        ]);
    }

    public function show(Request $request, MmmCard $mmmCard): Response
    {
        $mmmCard->load(['exercises', 'syllabusChapters.syllabusVersion.board', 'syllabusChapters.syllabusVersion.gradeLevel']);

        $boardId = $request->integer('board_id') ?: null;
        $gradeId = $request->integer('grade_level_id') ?: null;

        return Inertia::render('Admin/MmmCards/Show', [
            'card' => [
                'id' => $mmmCard->id,
                'slug' => $mmmCard->slug,
                'title' => $mmmCard->title,
                'heading' => $mmmCard->heading,
                'status' => $mmmCard->status,
                'accent' => $mmmCard->accent,
                'method_blurb' => $mmmCard->method_blurb,
                'table_schema' => $mmmCard->table_schema,
                'exercises' => $mmmCard->exercises->map(fn ($ex) => [
                    'id' => $ex->id,
                    'difficulty' => $ex->difficulty,
                    'is_demo' => $ex->is_demo,
                    'question_text' => $ex->question_text,
                    'sort_order' => $ex->sort_order,
                ]),
                'linked_chapters' => $mmmCard->syllabusChapters->map(fn ($ch) => [
                    'id' => $ch->id,
                    'label' => trim(($ch->chapter_number ? $ch->chapter_number.' · ' : '').$ch->name),
                    'board' => $ch->syllabusVersion?->board?->code,
                    'class' => $ch->syllabusVersion?->gradeLevel?->name,
                ])->values(),
            ],
            'boards' => Board::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'grades' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'picker' => [
                'board_id' => $boardId,
                'grade_level_id' => $gradeId,
                'chapters' => $this->mmmCards->chapterOptionsForPicker($boardId, $gradeId)->values(),
            ],
            'play_url' => route('admin.mmm-cards.play', $mmmCard),
        ]);
    }

    public function syncChapters(Request $request, MmmCard $mmmCard): RedirectResponse
    {
        $validated = $request->validate([
            'syllabus_chapter_ids' => ['required', 'array'],
            'syllabus_chapter_ids.*' => ['integer', 'exists:syllabus_chapters,id'],
            'mode' => ['nullable', 'in:replace,add'],
        ]);

        $mode = $validated['mode'] ?? 'add';
        $ids = collect($validated['syllabus_chapter_ids'])->map(fn ($id) => (int) $id)->unique()->values();

        if ($mode === 'replace') {
            $this->mmmCards->syncChapterLinks($mmmCard, $ids->all());
        } else {
            $merged = $mmmCard->syllabusChapters()->pluck('syllabus_chapters.id')
                ->merge($ids)
                ->unique()
                ->values()
                ->all();
            $this->mmmCards->syncChapterLinks($mmmCard, $merged);
        }

        return back()->with('success', 'Chapter links updated.');
    }

    public function unlinkChapter(MmmCard $mmmCard, int $syllabusChapter): RedirectResponse
    {
        $mmmCard->syllabusChapters()->detach($syllabusChapter);

        return back()->with('success', 'Chapter unlinked.');
    }

    public function play(Request $request, MmmCard $mmmCard): Response
    {
        abort_unless($mmmCard->isPublished() || auth()->user()?->isAdmin(), 404);

        $exerciseIndex = $request->filled('exercise')
            ? max(0, $request->integer('exercise') - 1)
            : 0;

        return Inertia::render('Student/MmmCards/Play', [
            ...$this->mmmCards->playPayload($mmmCard, null, $exerciseIndex),
            'check_url' => route('admin.mmm-cards.check', $mmmCard),
            'back_url' => route('admin.mmm-cards.show', $mmmCard),
            'is_staff_preview' => true,
        ]);
    }

    public function check(Request $request, MmmCard $mmmCard)
    {
        $validated = $request->validate([
            'exercise_id' => ['required', 'integer', 'exists:mmm_card_exercises,id'],
            'rows' => ['required', 'array'],
            'grand_total' => ['nullable'],
            'equation' => ['nullable', 'string', 'max:200'],
            'x_value' => ['nullable', 'string', 'max:40'],
        ]);

        $exercise = $mmmCard->exercises()->whereKey($validated['exercise_id'])->firstOrFail();
        $result = $this->mmmCards->checkSubmission($mmmCard, $exercise, $validated);

        $mmmCard->loadMissing('exercises');
        $orderedIds = $mmmCard->exercises->pluck('id')->values();
        $index = $orderedIds->search((int) $exercise->id);
        $hasNext = $index !== false && $index < $orderedIds->count() - 1;

        return response()->json([
            ...$result,
            'has_next' => $hasNext,
            'next_exercise' => $hasNext ? (int) $index + 2 : null,
            'received_equation' => $validated['equation'] ?? null,
        ]);
    }
}
