<?php

namespace App\Http\Controllers;

use App\Support\StudentWeeklyReportEmails;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StudentProfileController extends Controller
{
    public function updateWeeklyReportEmails(Request $request): RedirectResponse
    {
        $student = $request->user()->student;

        if (! $student) {
            abort(404);
        }

        $validated = $request->validate([
            'weekly_report_emails' => ['nullable', 'string', 'max:512'],
        ]);

        $emails = StudentWeeklyReportEmails::parse($validated['weekly_report_emails'] ?? '');

        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages([
                    'weekly_report_emails' => "Invalid email address: {$email}",
                ]);
            }
        }

        $student->update([
            'parent1_email' => $emails[0] ?? null,
            'parent2_email' => $emails[1] ?? null,
            'notify_parent1_email' => isset($emails[0]),
            'notify_parent2_email' => isset($emails[1]),
        ]);

        return back()->with('success', 'Parent email addresses saved for weekly progress reports.');
    }

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
