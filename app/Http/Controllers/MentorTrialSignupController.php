<?php

namespace App\Http\Controllers;

use App\Rules\UniqueStudentLoginEmail;
use App\Services\SelfServeAccessService;
use App\Support\AccessCodeMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MentorTrialSignupController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('MentorAccess/Create');
    }

    public function store(Request $request, SelfServeAccessService $selfServe): RedirectResponse
    {
        $validated = $request->validate([
            'class_name' => ['required', 'string', 'max:255'],
            'teacher_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:15'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueStudentLoginEmail,
                Rule::unique('users', 'email'),
            ],
        ], [
            'email.unique' => 'This email already has an account. Log in with your access code (tcode).',
        ]);

        $result = $selfServe->registerMentor($validated);

        AccessCodeMailer::sendIssued(
            $result['access_code'],
            $result['user']->email,
            $result['plain_code'],
            $result['user']->name,
            null,
            $validated['mobile'],
        );

        AccessCodeMailer::notifyAdmin(
            $result['access_code'],
            "Mentor {$validated['teacher_name']} · class {$validated['class_name']} · {$validated['email']}",
        );

        return redirect()
            ->route('mentor-access.thank-you')
            ->with('issued_access', [
                'email' => $result['user']->email,
                'code' => $result['plain_code'],
                'expires_on' => $result['access_code']->expires_at?->timezone(config('app.timezone'))->format('d M Y'),
            ]);
    }

    public function thankYou(): Response
    {
        return Inertia::render('MentorAccess/ThankYou', [
            'issuedAccess' => session('issued_access'),
        ]);
    }
}
