<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AcademicYearController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Masters/AcademicYears/Index', [
            'years' => AcademicYear::query()->orderByDesc('starts_on')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:academic_years,name'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $year = AcademicYear::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? false,
        ]);

        if ($year->is_active) {
            AcademicYear::activate($year);
        }

        return back()->with('success', 'Academic year created.');
    }

    public function activate(AcademicYear $academicYear): RedirectResponse
    {
        AcademicYear::activate($academicYear);

        return back()->with('success', "{$academicYear->name} is now the active academic year.");
    }
}
