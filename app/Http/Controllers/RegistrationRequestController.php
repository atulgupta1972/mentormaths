<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\RegistrationRequest;
use App\Rules\UniqueStudentIdentity;
use App\Rules\UniqueStudentLoginEmail;
use App\Support\RegistrationMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationRequestController extends Controller
{
    public function create(): Response|RedirectResponse
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return Inertia::render('Registration/Unavailable');
        }

        return Inertia::render('Registration/Create', [
            'academicYear' => $activeYear->only(['id', 'name']),
            'boards' => Board::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return redirect()->route('registration.create')
                ->with('error', 'Registration is not open for the current academic year.');
        }

        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'student_mobile' => ['required', 'string', 'max:15', new UniqueStudentIdentity],
            'parent1_name' => ['required', 'string', 'max:255'],
            'parent1_mobile' => ['required', 'string', 'max:15'],
            'parent2_name' => ['nullable', 'string', 'max:255'],
            'parent2_mobile' => ['nullable', 'string', 'max:15'],
            'school_name' => ['required', 'string', 'max:255'],
            'board_id' => ['required', 'exists:boards,id'],
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                new UniqueStudentLoginEmail,
                Rule::unique('registration_requests', 'email')->where(
                    fn ($query) => $query->where('status', RegistrationRequest::STATUS_PENDING),
                ),
            ],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'notify_student_mobile' => ['sometimes', 'boolean'],
            'notify_parent1_mobile' => ['sometimes', 'boolean'],
            'notify_parent2_mobile' => ['sometimes', 'boolean'],
        ], [
            'email.unique' => 'This login email is already registered or has a pending request. Try another email or log in.',
            'student_mobile.required' => 'Student mobile is required so we can identify returning students.',
        ]);

        $registrationRequest = RegistrationRequest::create([
            ...collect($validated)->except(['password', 'password_confirmation'])->all(),
            'password' => Hash::make($validated['password']),
            'academic_year_id' => $activeYear->id,
            'status' => RegistrationRequest::STATUS_PENDING,
        ]);

        RegistrationMailer::sendRequestReceived($registrationRequest);
        RegistrationMailer::notifyAdmin($registrationRequest);

        return redirect()->route('registration.thank-you');
    }

    public function thankYou(): Response
    {
        return Inertia::render('Registration/ThankYou');
    }
}
