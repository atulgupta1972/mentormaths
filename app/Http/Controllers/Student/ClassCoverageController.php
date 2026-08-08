<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\SyllabusChapter;
use App\Services\ClassCoverageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ClassCoverageController extends Controller
{
    public function __construct(private ClassCoverageService $coverageService) {}

    public function show(Request $request): Response
    {
        $enrollment = $request->user()->student?->currentEnrollment();
        $enrollment?->loadMissing(['gradeLevel:id,name', 'board:id,name']);

        return Inertia::render('Student/SchoolStudyPlan', [
            'classCoverage' => $this->coverageService->forEnrollment($enrollment),
            'context' => [
                'grade_name' => $enrollment?->gradeLevel?->name,
                'board_name' => $enrollment?->board?->name,
            ],
        ]);
    }

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

        return back()->with('success', 'School study plan updated.');
    }
}
