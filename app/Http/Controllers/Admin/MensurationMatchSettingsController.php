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
    public function __construct(
        private MensurationMatchService $mensuration,
    ) {}

    public function index(): Response
    {
        return Inertia::render('Admin/MensurationMatch/Index', [
            'rows' => $this->mensuration->adminRows(),
            'catalog_summary' => [
                'perimeter_area' => collect($this->mensuration->catalog())->where('board', 'perimeter_area')->count(),
                'volume' => collect($this->mensuration->catalog())->where('board', 'volume')->count(),
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

        return back()->with('success', $gradeLevel->name.' mensuration match settings saved.');
    }
}
