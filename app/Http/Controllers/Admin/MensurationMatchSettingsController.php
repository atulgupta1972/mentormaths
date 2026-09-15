<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Services\MensurationMatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MensurationMatchSettingsController extends Controller
{
    private const PREVIEW_SESSION_KEY = 'mensuration_match_admin_preview';

    public function __construct(
        private MensurationMatchService $mensuration,
    ) {}

    public function index(): Response
    {
        $sheet = $this->mensuration->adminFormulaSheet();

        return Inertia::render('Admin/MensurationMatch/Index', [
            'rows' => $this->mensuration->adminRows(),
            'sheet' => $sheet,
            'catalog_summary' => [
                'perimeter' => collect($this->mensuration->catalog())->where('measure', 'perimeter')->count(),
                'area' => collect($this->mensuration->catalog())->where('measure', 'area')->count(),
                'volume' => collect($this->mensuration->catalog())->where('measure', 'volume')->count(),
            ],
        ]);
    }

    public function update(Request $request, GradeLevel $gradeLevel): RedirectResponse
    {
        $validated = $request->validate([
            'enabled' => ['required', 'boolean'],
            'perimeter_area_enabled' => ['required', 'boolean'],
            'volume_enabled' => ['required', 'boolean'],
        ]);

        $this->mensuration->upsertForGrade($gradeLevel, $validated);

        return back()->with('success', $gradeLevel->name.' offer settings saved.');
    }

    public function updateSheet(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.key' => ['required', 'string', 'max:64'],
            'items.*.classes' => ['present', 'array'],
            'items.*.classes.*' => ['integer', 'min:1', 'max:12'],
        ]);

        $byKey = [];
        foreach ($validated['items'] as $row) {
            $byKey[$row['key']] = $row['classes'];
        }

        $this->mensuration->saveItemClasses($byKey);

        return back()->with('success', 'Mensuration formula class ticks saved.');
    }

    public function preview(Request $request, GradeLevel $gradeLevel): Response|RedirectResponse
    {
        $validated = $request->validate([
            'board' => ['required', 'string', 'in:perimeter_area,volume'],
            'restart' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('restart')) {
            try {
                $fresh = $this->mensuration->startAdminPreview($gradeLevel, $validated['board']);
            } catch (\InvalidArgumentException $e) {
                return redirect()
                    ->route('admin.mensuration-match.index')
                    ->with('error', $e->getMessage());
            }

            $request->session()->put(self::PREVIEW_SESSION_KEY, $fresh);

            return redirect()->route('admin.mensuration-match.preview', [
                'gradeLevel' => $gradeLevel->id,
                'board' => $validated['board'],
            ]);
        }

        $existing = $request->session()->get(self::PREVIEW_SESSION_KEY);
        $reuse = is_array($existing)
            && (int) ($existing['grade_level_id'] ?? 0) === (int) $gradeLevel->id
            && ($existing['board'] ?? null) === $validated['board'];

        try {
            $state = $reuse
                ? $existing
                : $this->mensuration->startAdminPreview($gradeLevel, $validated['board']);
        } catch (\InvalidArgumentException $e) {
            return redirect()
                ->route('admin.mensuration-match.index')
                ->with('error', $e->getMessage());
        }

        $request->session()->put(self::PREVIEW_SESSION_KEY, $state);

        return Inertia::render('Admin/MensurationMatch/Play', [
            'grade_name' => $gradeLevel->name,
            'play' => $this->mensuration->adminPlayPayload($state),
            'back_url' => route('admin.mensuration-match.index'),
            'restart_url' => route('admin.mensuration-match.preview', [
                'gradeLevel' => $gradeLevel->id,
                'board' => $validated['board'],
                'restart' => 1,
            ]),
        ]);
    }

    public function previewAnswer(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'item_key' => ['required', 'string', 'max:64'],
            'formula' => ['required', 'string', 'max:64'],
        ]);

        $state = $request->session()->get(self::PREVIEW_SESSION_KEY);
        if (! is_array($state)) {
            return redirect()
                ->route('admin.mensuration-match.index')
                ->with('error', 'Start a preview board first.');
        }

        try {
            $outcome = $this->mensuration->submitAdminPreviewAnswer(
                $state,
                $validated['item_key'],
                $validated['formula'],
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $request->session()->put(self::PREVIEW_SESSION_KEY, $outcome['state']);
        $result = $outcome['result'];

        return back()->with([
            'success' => $result['correct'] ? 'Correct — '.$result['explanation'] : null,
            'mensuration_flash' => $result,
        ]);
    }
}
