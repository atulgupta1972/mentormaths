<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ChapterHead;
use App\Services\ConceptOverviewService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConceptOverviewController extends Controller
{
    public function __construct(
        private ConceptOverviewService $overview,
    ) {}

    public function index(Request $request): Response
    {
        $yearId = $request->integer('year') ?: null;
        $boardId = $request->integer('board') ?: null;
        $branch = trim((string) $request->query('branch', ''));

        $payload = $this->overview->matrix($yearId ?: null, $boardId ?: null, $branch !== '' ? $branch : null);

        return Inertia::render('Admin/ConceptOverview/Index', [
            ...$payload,
            'filters' => [
                'year' => $yearId ?: ($payload['year']['id'] ?? null),
                'board' => $boardId ?: null,
                'branch' => $branch !== '' ? $branch : null,
                'compare' => $request->boolean('compare', true),
            ],
            'years' => AcademicYear::query()
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (AcademicYear $y) => [
                    'id' => $y->id,
                    'name' => $y->name,
                    'is_active' => (bool) $y->is_active,
                ]),
            'all_boards' => Board::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ]);
    }

    public function show(Request $request, ChapterHead $chapterHead): Response
    {
        $yearId = $request->integer('year') ?: null;
        $payload = $this->overview->progression($chapterHead, $yearId ?: null);

        return Inertia::render('Admin/ConceptOverview/Show', [
            ...$payload,
            'filters' => [
                'year' => $yearId ?: ($payload['year']['id'] ?? null),
            ],
            'years' => AcademicYear::query()
                ->orderByDesc('starts_on')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (AcademicYear $y) => [
                    'id' => $y->id,
                    'name' => $y->name,
                    'is_active' => (bool) $y->is_active,
                ]),
            'back_url' => route('admin.concept-overview.index', array_filter([
                'year' => $yearId ?: null,
            ])),
        ]);
    }
}
