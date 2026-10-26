<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\ChapterHead;
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
            ->with([
                'chapterHead',
                'gradeLevels',
                'syllabusChapters.syllabusVersion.board',
                'syllabusChapters.syllabusVersion.gradeLevel',
            ])
            ->withCount('exercises')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (MmmCard $card) => [
                'id' => $card->id,
                'slug' => $card->slug,
                'title' => $card->title,
                'heading' => $card->heading,
                'chapter_head' => $card->chapterHead?->name,
                'sub_heading' => $card->sub_heading,
                'classes' => $card->gradeLevels->sortBy('sort_order')->pluck('name')->values(),
                'status' => $card->status,
                'accent' => $card->accent,
                'exercises_count' => $card->exercises_count,
                'places' => $this->compactChapterPlaces($card),
            ]);

        return Inertia::render('Admin/MmmCards/Index', [
            'cards' => $cards,
        ]);
    }

    /**
     * @return list<array{board: ?string, class: ?string, chapters: list<string>}>
     */
    private function compactChapterPlaces(MmmCard $card): array
    {
        $groups = [];
        $chapters = $card->syllabusChapters->sortBy(fn ($chapter) => sprintf(
            '%05d-%05d-%05d',
            (int) ($chapter->syllabusVersion?->gradeLevel?->sort_order ?? 0),
            (int) ($chapter->syllabusVersion?->board?->id ?? 0),
            (int) ($chapter->sort_order ?? 0),
        ));

        foreach ($chapters as $chapter) {
            $board = $chapter->syllabusVersion?->board?->code;
            $class = $chapter->syllabusVersion?->gradeLevel?->name;
            $key = ($board ?: '').'|'.($class ?: '');
            $groups[$key] ??= [
                'board' => $board,
                'class' => $class,
                'chapters' => [],
            ];
            $label = trim(($chapter->chapter_number ? $chapter->chapter_number.' · ' : '').$chapter->name);
            if ($label !== '') {
                $groups[$key]['chapters'][] = $label;
            }
        }

        return array_values($groups);
    }

    public function show(Request $request, MmmCard $mmmCard): Response
    {
        $mmmCard->load([
            'exercises',
            'chapterHead',
            'gradeLevels',
            'syllabusChapters.syllabusVersion.board',
            'syllabusChapters.syllabusVersion.gradeLevel',
        ]);

        $boardId = $request->integer('board_id') ?: null;
        $gradeId = $request->integer('grade_level_id') ?: null;

        return Inertia::render('Admin/MmmCards/Show', [
            'card' => [
                'id' => $mmmCard->id,
                'slug' => $mmmCard->slug,
                'title' => $mmmCard->title,
                'heading' => $mmmCard->heading,
                'chapter_head_id' => $mmmCard->chapter_head_id,
                'sub_heading' => $mmmCard->sub_heading,
                'grade_level_ids' => $mmmCard->gradeLevels->pluck('id')->values(),
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
            'chapter_heads' => ChapterHead::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'sub_headings' => MmmCard::query()
                ->whereNotNull('sub_heading')
                ->where('sub_heading', '!=', '')
                ->distinct()
                ->orderBy('sub_heading')
                ->pluck('sub_heading')
                ->values(),
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

    public function classify(Request $request, MmmCard $mmmCard): RedirectResponse
    {
        $validated = $request->validate([
            'chapter_head_id' => ['nullable', 'integer', 'exists:chapter_heads,id'],
            'sub_heading' => ['nullable', 'string', 'max:120'],
            'heading' => ['required', 'string', 'max:120'],
            'method_blurb' => ['nullable', 'string', 'max:2000'],
            'grade_level_ids' => ['nullable', 'array'],
            'grade_level_ids.*' => ['integer', 'exists:grade_levels,id'],
        ]);

        $heading = trim($validated['heading']);
        $subHeading = trim((string) ($validated['sub_heading'] ?? ''));
        $about = trim((string) ($validated['method_blurb'] ?? ''));

        $mmmCard->update([
            'chapter_head_id' => $validated['chapter_head_id'] ?? null,
            'sub_heading' => $subHeading !== '' ? $subHeading : null,
            'heading' => $heading,
            'title' => $heading,
            'method_blurb' => $about !== '' ? $about : null,
        ]);
        $mmmCard->gradeLevels()->sync($validated['grade_level_ids'] ?? []);

        return back()->with('success', 'Method classification saved.');
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
            'rows' => ['nullable', 'array'],
            'grand_total' => ['nullable'],
            'grand_count' => ['nullable'],
            'equation' => ['nullable', 'string', 'max:200'],
            'coin_equation' => ['nullable', 'string', 'max:200'],
            'eq' => ['nullable', 'string', 'max:200'],
            'x_value' => ['nullable', 'string', 'max:40'],
            'step_key' => ['nullable', 'string', 'max:40'],
            'value' => ['nullable', 'string', 'max:40'],
            'values' => ['nullable', 'array'],
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
