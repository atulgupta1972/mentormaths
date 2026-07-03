<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ChapterHead;
use App\Models\SyllabusTopic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ChapterHeadController extends Controller
{
    public function index(): Response
    {
        $activeYear = AcademicYear::active();

        $chapterHeads = ChapterHead::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (ChapterHead $head) use ($activeYear) {
                $topicsCount = SyllabusTopic::query()
                    ->whereHas('chapter', fn ($q) => $q
                        ->where('chapter_head_id', $head->id)
                        ->when($activeYear, fn ($cq) => $cq->whereHas('syllabusVersion', fn ($vq) => $vq
                            ->where('academic_year_id', $activeYear->id))))
                    ->count();

                return [
                    'id' => $head->id,
                    'name' => $head->name,
                    'sort_order' => $head->sort_order,
                    'topics_count' => $topicsCount,
                ];
            });

        return Inertia::render('Admin/ChapterHeads/Index', [
            'chapterHeads' => $chapterHeads,
            'activeYear' => $activeYear?->only(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:chapter_heads,name'],
        ]);

        $sortOrder = (int) ChapterHead::query()->max('sort_order') + 1;

        ChapterHead::create([
            'name' => trim($validated['name']),
            'sort_order' => $sortOrder,
        ]);

        return back()->with('success', 'Chapter head added.');
    }

    public function show(ChapterHead $chapterHead): Response
    {
        $activeYear = AcademicYear::active();

        $topicsQuery = SyllabusTopic::query()
            ->with([
                'chapter.chapterHead',
                'chapter.syllabusVersion.board',
                'chapter.syllabusVersion.gradeLevel',
            ])
            ->whereHas('chapter', fn ($q) => $q->where('chapter_head_id', $chapterHead->id));

        if ($activeYear) {
            $topicsQuery->whereHas('chapter.syllabusVersion', fn ($q) => $q
                ->where('academic_year_id', $activeYear->id));
        }

        $topics = $topicsQuery
            ->get()
            ->sortBy(fn (SyllabusTopic $topic) => [
                $topic->chapter?->syllabusVersion?->gradeLevel?->sort_order ?? 99,
                $topic->chapter?->sort_order ?? 99,
                $topic->sort_order ?? 99,
            ])
            ->values()
            ->map(fn (SyllabusTopic $topic) => [
                'id' => $topic->id,
                'name' => $topic->name,
                'chapter_name' => $topic->chapter->name,
                'chapter_number' => $topic->chapter->chapter_number,
                'grade_name' => $topic->chapter->syllabusVersion?->gradeLevel?->name,
                'board_code' => $topic->chapter->syllabusVersion?->board?->code,
                'difficulty' => $topic->difficulty,
            ]);

        $byClass = $topics->groupBy('grade_name')->map(fn ($items, $className) => [
            'class_name' => $className ?: 'Unknown class',
            'topics' => $items->values()->all(),
        ])->values()->all();

        return Inertia::render('Admin/ChapterHeads/Show', [
            'chapterHead' => $chapterHead->only(['id', 'name']),
            'topics' => $topics->all(),
            'topicsByClass' => $byClass,
            'activeYear' => $activeYear?->only(['id', 'name']),
        ]);
    }

    public function update(Request $request, ChapterHead $chapterHead): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:chapter_heads,name,'.$chapterHead->id],
        ]);

        $chapterHead->update(['name' => trim($validated['name'])]);

        return back()->with('success', 'Chapter head updated.');
    }

    public function destroy(ChapterHead $chapterHead): RedirectResponse
    {
        $chapterHead->chapters()->update(['chapter_head_id' => null]);
        $chapterHead->delete();

        return redirect()
            ->route('admin.chapter-heads.index')
            ->with('success', 'Chapter head removed.');
    }
}
