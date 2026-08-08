<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SyllabusChapter;
use App\Services\ClassCoverageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ClassCoverageController extends Controller
{
    public function __construct(private ClassCoverageService $coverageService) {}

    public function update(Request $request, SyllabusChapter $syllabusChapter): RedirectResponse
    {
        $enrollment = $request->user()->student?->currentEnrollment();

        if (! $enrollment) {
            return back()->with('error', 'No active enrollment for this year.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:studied,under_study'],
        ]);

        if ($validated['status'] === 'under_study') {
            $this->coverageService->markUnderStudy($enrollment, $syllabusChapter);
        } else {
            $this->coverageService->markStudied($enrollment, $syllabusChapter);
        }

        return back()->with('success', 'Class coverage updated.');
    }
}
