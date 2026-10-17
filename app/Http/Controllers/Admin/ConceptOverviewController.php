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
        $classNumber = $request->integer('class') ?: null;
        $branch = trim((string) $request->query('branch', ''));

        $payload = $this->overview->matrix(
            $yearId ?: null,
            $boardId ?: null,
            $branch !== '' ? $branch : null,
            $classNumber ?: null,
        );

        $canManage = (bool) $request->user()?->isAdmin();

        return Inertia::render('Admin/ConceptOverview/Index', [
            ...$this->sanitizeForViewer($payload, $canManage),
            'can_manage' => $canManage,
            'filters' => [
                'year' => $yearId ?: ($payload['year']['id'] ?? null),
                'board' => $boardId ?: null,
                'class' => $classNumber ?: null,
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
        $canManage = (bool) $request->user()?->isAdmin();

        return Inertia::render('Admin/ConceptOverview/Show', [
            ...$this->sanitizeProgressionForViewer($payload, $canManage),
            'can_manage' => $canManage,
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

    /**
     * Strip create/edit URLs for non-admin viewers (run-only).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeForViewer(array $payload, bool $canManage): array
    {
        if ($canManage) {
            return $payload;
        }

        $stripCell = function (array $cell) use (&$stripCell): array {
            $cell['concept_url'] = null;
            $cell['syllabus_url'] = null;
            if (isset($cell['entries']) && is_array($cell['entries'])) {
                $cell['entries'] = array_map($stripCell, $cell['entries']);
            }

            return $cell;
        };

        foreach ($payload['branches'] ?? [] as $bi => $branch) {
            foreach ($branch['rows'] ?? [] as $ri => $row) {
                foreach ($row['cells'] ?? [] as $classKey => $classCell) {
                    foreach ($classCell['boards'] ?? [] as $code => $boardCell) {
                        $payload['branches'][$bi]['rows'][$ri]['cells'][$classKey]['boards'][$code] = $stripCell($boardCell);
                    }
                }
            }
        }

        foreach ($payload['rows'] ?? [] as $ri => $row) {
            foreach ($row['cells'] ?? [] as $classKey => $classCell) {
                foreach ($classCell['boards'] ?? [] as $code => $boardCell) {
                    $payload['rows'][$ri]['cells'][$classKey]['boards'][$code] = $stripCell($boardCell);
                }
            }
        }

        foreach ($payload['class_cards'] ?? [] as $i => $card) {
            $payload['class_cards'][$i]['concept_url'] = null;
            $payload['class_cards'][$i]['syllabus_url'] = null;
        }

        $payload['untagged_chapters'] = [];
        $payload['orphan_tagged_chapters'] = [];

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeProgressionForViewer(array $payload, bool $canManage): array
    {
        if ($canManage) {
            return $payload;
        }

        foreach ($payload['ladder'] ?? [] as $li => $rung) {
            foreach ($rung['boards'] ?? [] as $bi => $board) {
                $payload['ladder'][$li]['boards'][$bi]['concept_url'] = null;
                $payload['ladder'][$li]['boards'][$bi]['syllabus_url'] = null;
                $payload['ladder'][$li]['boards'][$bi]['topics'] = [];
            }
        }

        return $payload;
    }
}
