<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $student = $user->student;
        $enrollment = $student?->currentEnrollment();

        if ($enrollment) {
            $enrollment->load(['board:id,code,name', 'gradeLevel:id,name', 'academicYear:id,name']);
        }

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'studentProfile' => $student ? [
                'id' => $student->id,
                'name' => $student->name,
                'date_of_birth' => $student->date_of_birth?->format('Y-m-d'),
                'student_mobile' => $student->student_mobile,
                'parent1_name' => $student->parent1_name,
                'parent1_mobile' => $student->parent1_mobile,
                'parent2_name' => $student->parent2_name,
                'parent2_mobile' => $student->parent2_mobile,
                'school_name' => $student->school_name,
                'notify_student_mobile' => $student->notify_student_mobile,
                'notify_parent1_mobile' => $student->notify_parent1_mobile,
                'notify_parent2_mobile' => $student->notify_parent2_mobile,
                'enrollment' => $enrollment ? [
                    'class' => $enrollment->gradeLevel?->name,
                    'board' => $enrollment->board?->name,
                    'academic_year' => $enrollment->academicYear?->name,
                    'school_name' => $enrollment->school_name,
                ] : null,
            ] : null,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
