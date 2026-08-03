<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Services\BasicsDrillSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BasicsDrillSettingsController extends Controller
{
    public function __construct(private BasicsDrillSettingsService $settingsService) {}

    public function index(): Response
    {
        return Inertia::render('Admin/BasicsDrill/Index', [
            'rows' => $this->settingsService->adminIndexRows(),
            'defaults' => $this->settingsService->defaults(),
        ]);
    }

    public function update(Request $request, GradeLevel $gradeLevel): RedirectResponse
    {
        $validated = $request->validate([
            'tables_enabled' => ['sometimes', 'boolean'],
            'squares_enabled' => ['sometimes', 'boolean'],
            'cubes_enabled' => ['sometimes', 'boolean'],
            'table_from' => ['required', 'integer', 'min:2', 'max:30'],
            'table_to' => ['required', 'integer', 'min:2', 'max:30', 'gte:table_from'],
            'multiplier_from' => ['required', 'integer', 'min:2', 'max:9'],
            'multiplier_to' => ['required', 'integer', 'min:2', 'max:9', 'gte:multiplier_from'],
            'square_from' => ['required', 'integer', 'min:2', 'max:30'],
            'square_to' => ['required', 'integer', 'min:2', 'max:30', 'gte:square_from'],
            'cube_from' => ['required', 'integer', 'min:2', 'max:20'],
            'cube_to' => ['required', 'integer', 'min:2', 'max:20', 'gte:cube_from'],
            'squares_per_day' => ['required', 'integer', 'min:1', 'max:10'],
            'cubes_per_day' => ['required', 'integer', 'min:1', 'max:5'],
            'seconds_per_blank' => ['required', 'integer', 'min:3', 'max:15'],
        ]);

        $this->settingsService->upsertForGrade($gradeLevel, $validated);

        return back()->with('success', "Basics drill settings saved for {$gradeLevel->name}.");
    }
}
