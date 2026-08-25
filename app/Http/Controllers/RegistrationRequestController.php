<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\CoachingClass;
use App\Models\CoachingClassTeacher;
use App\Models\GradeLevel;
use App\Models\RegistrationRequest;
use App\Rules\UniqueStudentIdentity;
use App\Rules\UniqueStudentLoginEmail;
use App\Services\SelfServeAccessService;
use App\Support\AccessCodeMailer;
use App\Support\EnrollmentSource;
use App\Support\StudentOnboardingMailer;
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

        $coachingClasses = CoachingClass::query()
            ->where('is_active', true)
            ->with(['teachers' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->orderBy('name')
            ->get(['id', 'name', 'city']);

        return Inertia::render('Registration/Create', [
            'academicYear' => $activeYear->only(['id', 'name']),
            'boards' => Board::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'gradeLevels' => GradeLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name']),
            'enrollmentOptions' => EnrollmentSource::optionsForUi(),
            'coachingClasses' => $coachingClasses,
            'trialDays' => (int) config('access.trial_days', 15),
        ]);
    }

    public function store(Request $request, SelfServeAccessService $selfServe): RedirectResponse
    {
        $activeYear = AcademicYear::active();

        if (! $activeYear) {
            return redirect()->route('registration.create')
                ->with('error', 'Registration is not open for the current academic year.');
        }

        if (! $request->filled('password')) {
            $request->merge([
                'password' => null,
                'password_confirmation' => null,
            ]);
        }

        $validated = $request->validate([
            'student_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'student_mobile' => ['required', 'string', 'max:15', new UniqueStudentIdentity],
            'parent1_name' => [
                Rule::requiredIf(fn () => $request->input('enrollment_source') !== EnrollmentSource::COACHING),
                'nullable',
                'string',
                'max:255',
            ],
            'parent1_mobile' => [
                Rule::requiredIf(fn () => $request->input('enrollment_source') !== EnrollmentSource::COACHING),
                'nullable',
                'string',
                'max:15',
            ],
            'parent1_email' => ['nullable', 'string', 'email', 'max:255'],
            'parent2_name' => ['nullable', 'string', 'max:255'],
            'parent2_mobile' => ['nullable', 'string', 'max:15'],
            'school_name' => ['required', 'string', 'max:255'],
            'enrollment_source' => ['required', Rule::in(EnrollmentSource::active())],
            'coaching_class_id' => [
                Rule::requiredIf(fn () => $request->input('enrollment_source') === EnrollmentSource::COACHING),
                'nullable',
                'integer',
                Rule::exists('coaching_classes', 'id'),
            ],
            'coaching_class_teacher_id' => [
                Rule::requiredIf(fn () => $request->input('enrollment_source') === EnrollmentSource::COACHING),
                'nullable',
                'integer',
                Rule::exists('coaching_class_teachers', 'id'),
            ],
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
            'password' => ['nullable', 'string', 'confirmed', Password::defaults()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'notify_student_mobile' => ['sometimes', 'boolean'],
            'notify_parent1_mobile' => ['sometimes', 'boolean'],
            'notify_parent2_mobile' => ['sometimes', 'boolean'],
        ], [
            'email.unique' => 'This login email is already registered or has a pending request. Try another email or log in.',
            'student_mobile.required' => 'Student mobile is required so we can identify returning students.',
            'parent1_name.required' => 'Mentor name is required for individual (home) enrollment.',
            'parent1_mobile.required' => 'Mentor mobile is required for individual (home) enrollment.',
        ]);

        if (($validated['enrollment_source'] ?? '') !== EnrollmentSource::COACHING) {
            $validated['coaching_class_id'] = null;
            $validated['coaching_class_teacher_id'] = null;
        }

        // Coaching: mentor comes from teacher dropdown — copy into parent1 for storage / WhatsApp.
        if (($validated['enrollment_source'] ?? '') === EnrollmentSource::COACHING) {
            $teacher = CoachingClassTeacher::query()
                ->whereKey($validated['coaching_class_teacher_id'])
                ->where('coaching_class_id', $validated['coaching_class_id'])
                ->where('is_active', true)
                ->first();

            if (! $teacher) {
                return back()->withErrors([
                    'coaching_class_teacher_id' => 'Select a teacher from the chosen coaching class.',
                ])->withInput();
            }

            $validated['parent1_name'] = $teacher->name;
            $validated['parent1_mobile'] = $teacher->mobile;
            $validated['parent1_email'] = $teacher->email ?: ($validated['parent1_email'] ?? null);
            $validated['notify_parent1_mobile'] = filled($teacher->mobile);
        }

        // Home learning: mentor = parent1 contact (single mentor; replaces prior mapping).
        if (($validated['enrollment_source'] ?? '') === EnrollmentSource::INDIVIDUAL) {
            $validated['notify_parent1_mobile'] = true;
            $validated['parent1_email'] = $validated['parent1_email'] ?? $validated['email'];
        }

        $mentorCheck = app(\App\Services\StudentMentorService::class)->validateMappingPayload($validated);
        if (! $mentorCheck['ok']) {
            return back()->withErrors(['enrollment_source' => $mentorCheck['message']])->withInput();
        }

        $chosenPassword = $validated['password'] ?? null;

        $registrationRequest = RegistrationRequest::create([
            ...collect($validated)->except(['password', 'password_confirmation'])->all(),
            'password' => filled($chosenPassword) ? Hash::make($chosenPassword) : null,
            'academic_year_id' => $activeYear->id,
            'status' => RegistrationRequest::STATUS_PENDING,
            'enrollment_source' => $validated['enrollment_source'] ?? EnrollmentSource::INDIVIDUAL,
        ]);

        $result = $selfServe->activateStudentRegistration($registrationRequest, $chosenPassword);

        $mobiles = array_values(array_filter([
            $registrationRequest->parent1_mobile,
            $registrationRequest->notify_student_mobile ? $registrationRequest->student_mobile : null,
        ]));

        AccessCodeMailer::sendIssued(
            $result['access_code'],
            $result['login_email'],
            $result['plain_code'],
            $registrationRequest->student_name,
            $registrationRequest->parent1_email,
            $mobiles,
        );

        StudentOnboardingMailer::send(
            $result['student'],
            $result['login_email'],
            $registrationRequest->parent1_email,
        );

        AccessCodeMailer::notifyAdmin(
            $result['access_code'],
            "Student {$registrationRequest->student_name} · {$result['login_email']} · mentor {$registrationRequest->parent1_name}",
        );

        return redirect()
            ->route('registration.thank-you')
            ->with('issued_access', [
                'email' => $result['login_email'],
                'code' => $result['plain_code'],
                'expires_on' => $result['access_code']->expires_at?->timezone(config('app.timezone'))->format('d M Y'),
            ]);
    }

    public function thankYou(): Response
    {
        return Inertia::render('Registration/ThankYou', [
            'issuedAccess' => session('issued_access'),
        ]);
    }
}
