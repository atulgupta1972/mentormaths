<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\ContentRateCard;
use App\Models\GradeLevel;
use App\Models\SyllabusChapter;
use App\Services\ContentRateCardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ContentRateCardController extends Controller
{
    public function __construct(private ContentRateCardService $rateCardService) {}

    public function index(): Response
    {
        $cards = ContentRateCard::query()
            ->with(['board:id,name', 'gradeLevel:id,name', 'syllabusChapter:id,name,chapter_number'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (ContentRateCard $card) => [
                'id' => $card->id,
                'board' => $card->board?->only(['id', 'name']),
                'grade_level' => $card->gradeLevel?->only(['id', 'name']),
                'syllabus_chapter' => $card->syllabusChapter ? [
                    'id' => $card->syllabusChapter->id,
                    'name' => $card->syllabusChapter->name,
                    'chapter_number' => $card->syllabusChapter->chapter_number,
                ] : null,
                'content_type' => $card->content_type,
                'default_amount_inr' => $card->default_amount_inr,
                'admin_notes' => $card->admin_notes,
            ]);

        return Inertia::render('Admin/ContentRateCards/Index', [
            'rateCards' => $cards,
            'boards' => Board::query()->orderBy('name')->get(['id', 'name']),
            'gradeLevels' => GradeLevel::query()->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'board_id' => ['nullable', 'exists:boards,id'],
            'grade_level_id' => ['nullable', 'exists:grade_levels,id'],
            'syllabus_chapter_id' => ['nullable', 'exists:syllabus_chapters,id'],
            'content_type' => ['required', 'string', 'max:64'],
            'default_amount_inr' => ['required', 'integer', 'min:100', 'max:500000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        ContentRateCard::create([
            ...$validated,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Rate card saved.');
    }

    public function update(Request $request, ContentRateCard $contentRateCard): RedirectResponse
    {
        $validated = $request->validate([
            'default_amount_inr' => ['required', 'integer', 'min:100', 'max:500000'],
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $contentRateCard->update([
            ...$validated,
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Rate updated.');
    }
}
