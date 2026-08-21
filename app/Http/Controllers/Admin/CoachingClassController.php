<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CoachingClassController extends Controller
{
    public function index(): Response
    {
        $classes = CoachingClass::query()
            ->withCount(['teachers', 'students'])
            ->with(['teachers' => fn ($q) => $q->orderBy('sort_order')->orderBy('name')])
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Masters/CoachingClasses/Index', [
            'classes' => $classes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        CoachingClass::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        return back()->with('success', 'Coaching class created.');
    }

    public function update(Request $request, CoachingClass $coachingClass): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $coachingClass->update([
            ...$validated,
            'is_active' => $validated['is_active'] ?? $coachingClass->is_active,
        ]);

        return back()->with('success', 'Coaching class updated.');
    }

    public function storeTeacher(Request $request, CoachingClass $coachingClass): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
        ]);

        $coachingClass->teachers()->create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => (int) $coachingClass->teachers()->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Teacher added.');
    }

    public function updateTeacher(Request $request, CoachingClassTeacher $teacher): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['boolean'],
            'coaching_class_id' => [
                'sometimes',
                'integer',
                Rule::exists('coaching_classes', 'id'),
            ],
        ]);

        $teacher->update([
            ...$validated,
            'is_active' => $validated['is_active'] ?? $teacher->is_active,
        ]);

        return back()->with('success', 'Teacher updated.');
    }

    public function destroyTeacher(CoachingClassTeacher $teacher): RedirectResponse
    {
        if ($teacher->students()->exists()) {
            return back()->with('error', 'Cannot delete: students are mapped to this teacher. Deactivate instead.');
        }

        $teacher->delete();

        return back()->with('success', 'Teacher removed.');
    }
}
