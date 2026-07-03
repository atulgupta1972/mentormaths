<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class StudentProfileController extends Controller
{
    public function updateContacts(Request $request): RedirectResponse
    {
        $student = $request->user()->student;

        if (! $student) {
            abort(404);
        }

        $validated = $request->validate([
            'student_mobile' => ['nullable', 'string', 'max:15'],
            'parent1_name' => ['required', 'string', 'max:255'],
            'parent1_mobile' => ['required', 'string', 'max:15'],
            'parent2_name' => ['nullable', 'string', 'max:255'],
            'parent2_mobile' => ['nullable', 'string', 'max:15'],
            'notify_student_mobile' => ['sometimes', 'boolean'],
            'notify_parent1_mobile' => ['sometimes', 'boolean'],
            'notify_parent2_mobile' => ['sometimes', 'boolean'],
        ]);

        $student->update($validated);

        if ($request->user() && array_key_exists('student_mobile', $validated)) {
            $request->user()->update(['mobile' => $validated['student_mobile']]);
        }

        return back()->with('success', 'Profile and notification settings saved.');
    }
}
