<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminGradeContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GradeContextController extends Controller
{
    public function __construct(private AdminGradeContext $gradeContext) {}

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'grade_level_id' => ['nullable', 'integer', 'exists:grade_levels,id'],
        ]);

        $this->gradeContext->persist($request, $validated['grade_level_id'] ?? null);

        return back();
    }
}
