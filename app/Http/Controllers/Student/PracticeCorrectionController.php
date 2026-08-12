<?php

namespace App\Http\Controllers\Student;

use App\Http\Controllers\Controller;
use App\Models\Worksheet;
use App\Services\PracticeCorrectionPracticeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PracticeCorrectionController extends Controller
{
    public function __construct(private PracticeCorrectionPracticeService $correctionPractice) {}

    public function store(Request $request, Worksheet $worksheet): RedirectResponse
    {
        $user = $request->user();
        $student = $user->student;

        if (! $student) {
            abort(403, 'Only students can redo wrong questions.');
        }

        try {
            $attempt = $this->correctionPractice->start($student, $worksheet, $user);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('student.attempts.show', $attempt)
            ->with('success', 'Redo wrong — answer each question again.');
    }
}
