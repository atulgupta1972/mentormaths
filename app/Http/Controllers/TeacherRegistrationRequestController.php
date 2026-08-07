<?php

namespace App\Http\Controllers;

use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\TeacherRegistrationRequest;
use App\Models\User;
use App\Services\TeacherRegistrationService;
use App\Support\TeacherRegistrationMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class TeacherRegistrationRequestController extends Controller
{
    public function __construct(private TeacherRegistrationService $service) {}

    public function create(): Response
    {
        return Inertia::render('TeacherRegistration/Create', $this->formOptions());
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validateApplication($request);

        $resumePath = null;
        $resumeOriginalName = null;

        if ($request->hasFile('resume')) {
            $resumePath = $request->file('resume')->store('mentor-registration-resumes', 'public');
            $resumeOriginalName = $request->file('resume')->getClientOriginalName();
        }

        $application = TeacherRegistrationRequest::create([
            ...collect($validated)->except(['password', 'password_confirmation', 'resume'])->all(),
            'password' => Hash::make($validated['password']),
            'resume_path' => $resumePath,
            'resume_original_name' => $resumeOriginalName,
            'status' => TeacherRegistrationRequest::STATUS_PENDING,
            'agreed_at' => now(),
            'mentoring_agreed_at' => ($validated['agreed_to_mentoring_program'] ?? false) ? now() : null,
        ]);

        TeacherRegistrationMailer::sendRequestReceived($application);
        TeacherRegistrationMailer::notifyAdmin($application);

        return redirect()->route('teacher-registration.thank-you');
    }

    public function thankYou(): Response
    {
        return Inertia::render('TeacherRegistration/ThankYou');
    }

    public function showOffer(string $token): Response|RedirectResponse
    {
        $application = TeacherRegistrationRequest::query()
            ->where('counter_offer_token', $token)
            ->firstOrFail();

        if (! $application->canRespondToOffer()) {
            return Inertia::render('TeacherRegistration/OfferClosed', [
                'application' => [
                    'name' => $application->name,
                    'status' => $application->status,
                    'status_label' => TeacherRegistrationRequest::statusLabel($application->status),
                ],
            ]);
        }

        return Inertia::render('TeacherRegistration/OfferResponse', [
            'application' => [
                'name' => $application->name,
                'proposed_hourly_rate_inr' => $application->proposed_hourly_rate_inr,
                'counter_hourly_rate_inr' => $application->counter_hourly_rate_inr,
                'counter_offer_message' => $application->counter_offer_message,
                'doubt_sessions_per_week' => $application->doubt_sessions_per_week,
                'doubt_hours_per_week' => $application->doubt_hours_per_week,
            ],
            'token' => $token,
        ]);
    }

    public function respondToOffer(Request $request, string $token): RedirectResponse
    {
        $application = TeacherRegistrationRequest::query()
            ->where('counter_offer_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'response' => ['required', Rule::in([
                TeacherRegistrationRequest::OFFER_ACCEPTED,
                TeacherRegistrationRequest::OFFER_DECLINED,
            ])],
        ]);

        try {
            $this->service->respondToOffer($application, $validated['response']);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('teacher-registration.offer', $token)
            ->with('success', $validated['response'] === TeacherRegistrationRequest::OFFER_ACCEPTED
                ? 'Thank you — we have recorded your acceptance. We will email you when your account is approved.'
                : 'We have recorded your response. Thank you for your interest.');
    }

    public function showCompleteProfile(string $token): Response|RedirectResponse
    {
        $application = TeacherRegistrationRequest::query()
            ->where('profile_completion_token', $token)
            ->firstOrFail();

        if (! $application->canCompleteProfileViaToken()) {
            return redirect()->route('teacher-registration.create')
                ->with('warning', 'This profile link is no longer valid.');
        }

        return Inertia::render('TeacherRegistration/CompleteProfile', [
            'application' => [
                'name' => $application->name,
                'email' => $application->email,
                'city' => $application->city,
                'state' => $application->state,
                'country' => $application->country ?: 'India',
                'teaches_english_medium' => (bool) $application->teaches_english_medium,
                'teaches_hindi_medium' => (bool) $application->teaches_hindi_medium,
                'regional_language' => $application->regional_language,
                'missing_profile_field_labels' => collect($application->missingProfileFields())
                    ->map(fn (string $field) => TeacherRegistrationRequest::missingProfileFieldLabel($field))
                    ->values()
                    ->all(),
            ],
            'token' => $token,
        ]);
    }

    public function updateCompleteProfile(Request $request, string $token): RedirectResponse
    {
        $application = TeacherRegistrationRequest::query()
            ->where('profile_completion_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'teaches_english_medium' => ['sometimes', 'boolean'],
            'teaches_hindi_medium' => ['sometimes', 'boolean'],
            'regional_language' => ['nullable', 'string', 'max:120'],
        ]);

        $validated['teaches_english_medium'] = (bool) ($validated['teaches_english_medium'] ?? false);
        $validated['teaches_hindi_medium'] = (bool) ($validated['teaches_hindi_medium'] ?? false);
        $validated['country'] = $validated['country'] ?: 'India';

        if (! $validated['teaches_english_medium'] && ! $validated['teaches_hindi_medium']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'teaches_english_medium' => 'Select at least one language: English and/or Hindi.',
            ]);
        }

        try {
            $this->service->updateProfileDetails($application, $validated);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('teacher-registration.thank-you')
            ->with('success', 'Thank you — your location and language details have been saved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        return [
            'boards' => Board::query()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
            'gradeLevels' => GradeLevel::query()
                ->where('is_active', true)
                ->whereBetween('sort_order', [4, 12])
                ->orderBy('sort_order')
                ->get(['id', 'name', 'sort_order']),
            'preferredDayOptions' => [
                ['value' => 'mon', 'label' => 'Monday'],
                ['value' => 'tue', 'label' => 'Tuesday'],
                ['value' => 'wed', 'label' => 'Wednesday'],
                ['value' => 'thu', 'label' => 'Thursday'],
                ['value' => 'fri', 'label' => 'Friday'],
                ['value' => 'sat', 'label' => 'Saturday'],
                ['value' => 'sun', 'label' => 'Sunday'],
            ],
            'referralOptions' => [
                'Website / enquiry form',
                'WhatsApp / social media',
                'Friend or colleague',
                'School network',
                'Other',
            ],
            'genderOptions' => [
                ['value' => 'female', 'label' => 'Female'],
                ['value' => 'male', 'label' => 'Male'],
                ['value' => 'other', 'label' => 'Other'],
                ['value' => 'prefer_not_to_say', 'label' => 'Prefer not to say'],
            ],
            'platformUsageOptions' => collect(TeacherRegistrationRequest::PLATFORM_USAGE_SCOPES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
            'mentorMathsFeatures' => collect(TeacherRegistrationRequest::MENTOR_MATHS_FEATURES)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validateApplication(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email'),
                Rule::unique('teacher_registration_requests', 'email')->where(
                    fn ($query) => $query->whereNotIn('status', [
                        TeacherRegistrationRequest::STATUS_REJECTED,
                        TeacherRegistrationRequest::STATUS_OFFER_DECLINED,
                    ]),
                ),
            ],
            'mobile' => ['required', 'string', 'max:15'],
            'gender' => ['required', 'string', Rule::in(['female', 'male', 'other', 'prefer_not_to_say'])],
            'date_of_birth' => ['required', 'date', 'before:today', 'after:1950-01-01'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['required', 'string', 'max:120'],
            'country' => ['nullable', 'string', 'max:80'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'current_role' => ['nullable', 'string', 'max:255'],
            'years_of_experience' => ['required', 'integer', 'min:0', 'max:60'],
            'bio' => ['nullable', 'string', 'max:2000'],
            'resume' => ['nullable', 'file', 'mimes:pdf,doc,docx', 'max:5120'],
            'monitoring_platform_name' => ['nullable', 'string', 'max:255'],
            'platform_usage_scope' => ['nullable', 'string', Rule::in(array_keys(TeacherRegistrationRequest::PLATFORM_USAGE_SCOPES))],
            'current_tool_features' => ['nullable', 'array'],
            'current_tool_features.*' => ['string', Rule::in(array_keys(TeacherRegistrationRequest::MENTOR_MATHS_FEATURES))],
            'platform_experience_notes' => ['nullable', 'string', 'max:2000'],
            'board_ids' => ['required', 'array', 'min:1'],
            'board_ids.*' => ['integer', 'exists:boards,id'],
            'teaching_grade_level_ids' => ['nullable', 'array'],
            'teaching_grade_level_ids.*' => ['integer', 'exists:grade_levels,id'],
            'content_grade_level_ids' => ['nullable', 'array'],
            'content_grade_level_ids.*' => ['integer', 'exists:grade_levels,id'],
            'interested_in_content_creation' => ['sometimes', 'boolean'],
            'creates_mcq' => ['sometimes', 'boolean'],
            'creates_fill_blank' => ['sometimes', 'boolean'],
            'creates_written_sheets' => ['sometimes', 'boolean'],
            'creates_chapter_tests' => ['sometimes', 'boolean'],
            'creates_formula_drills' => ['sometimes', 'boolean'],
            'sample_work_url' => ['nullable', 'string', 'max:500'],
            'interested_in_book_content_upload' => ['sometimes', 'boolean'],
            'proposed_rate_per_set_inr' => ['nullable', 'integer', 'min:50', 'max:100000'],
            'interested_in_doubt_solving' => ['sometimes', 'boolean'],
            'agreed_to_mentoring_program' => ['sometimes', 'boolean'],
            'doubt_sessions_per_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'doubt_hours_per_week' => ['nullable', 'numeric', 'min:0.5', 'max:40'],
            'proposed_hourly_rate_inr' => ['nullable', 'integer', 'min:100', 'max:100000'],
            'preferred_days' => ['nullable', 'array'],
            'preferred_days.*' => ['string', 'max:10'],
            'preferred_time_slot' => ['nullable', 'string', 'max:255'],
            'expected_start_date' => ['nullable', 'date', 'after_or_equal:today'],
            'teaches_english_medium' => ['sometimes', 'boolean'],
            'teaches_hindi_medium' => ['sometimes', 'boolean'],
            'regional_language' => ['nullable', 'string', 'max:120'],
            'referral_source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'agreed_to_terms' => ['accepted'],
        ], [
            'email.unique' => 'This email is already registered or has an open application.',
        ]);

        $validated['interested_in_content_creation'] = (bool) ($validated['interested_in_content_creation'] ?? false);
        $validated['interested_in_book_content_upload'] = (bool) ($validated['interested_in_book_content_upload'] ?? false);
        $validated['interested_in_doubt_solving'] = (bool) ($validated['interested_in_doubt_solving'] ?? false);

        if (! $validated['interested_in_content_creation']
            && ! $validated['interested_in_doubt_solving']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'interested_in_content_creation' => 'Select at least one: question bank creation or online mentoring.',
            ]);
        }

        if ($validated['interested_in_book_content_upload'] && ! $validated['interested_in_content_creation']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'interested_in_content_creation' => 'Book content upload is part of question bank creation — please enable that section.',
            ]);
        }

        $validated['agreed_to_mentoring_program'] = (bool) ($validated['agreed_to_mentoring_program'] ?? false);

        if ($validated['interested_in_content_creation']) {
            if (empty($validated['content_grade_level_ids'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'content_grade_level_ids' => 'Select at least one class for question bank work.',
                ]);
            }

            $hasContentType = ($validated['creates_mcq'] ?? false)
                || ($validated['creates_fill_blank'] ?? false)
                || ($validated['creates_written_sheets'] ?? false)
                || ($validated['creates_chapter_tests'] ?? false)
                || ($validated['creates_formula_drills'] ?? false)
                || $validated['interested_in_book_content_upload'];

            if (! $hasContentType) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'interested_in_content_creation' => 'Select at least one content type (including book content upload if applicable).',
                ]);
            }

            $request->validate([
                'proposed_rate_per_set_inr' => ['required', 'integer', 'min:50', 'max:100000'],
            ]);

            $validated['proposed_rate_per_set_inr'] = (int) $request->input('proposed_rate_per_set_inr');
        } else {
            $validated['interested_in_book_content_upload'] = false;
            $validated['proposed_rate_per_set_inr'] = null;
        }

        if ($validated['interested_in_doubt_solving']) {
            if (empty($validated['teaching_grade_level_ids'])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'teaching_grade_level_ids' => 'Select at least one class you can mentor online.',
                ]);
            }

            $request->validate([
                'doubt_sessions_per_week' => ['required', 'integer', 'min:1', 'max:7'],
                'doubt_hours_per_week' => ['required', 'numeric', 'min:0.5', 'max:40'],
                'proposed_hourly_rate_inr' => ['required', 'integer', 'min:100', 'max:100000'],
                'expected_start_date' => ['required', 'date', 'after_or_equal:today'],
                'preferred_days' => ['required', 'array', 'min:1'],
                'preferred_time_slot' => ['required', 'string', 'max:255'],
                'agreed_to_mentoring_program' => ['accepted'],
            ], [
                'agreed_to_mentoring_program.accepted' => 'Please accept the online mentoring model and weekly schedule commitment.',
            ]);

            $validated['agreed_to_mentoring_program'] = true;

            $validated = array_merge($validated, $request->only([
                'doubt_sessions_per_week',
                'doubt_hours_per_week',
                'proposed_hourly_rate_inr',
                'expected_start_date',
                'preferred_days',
                'preferred_time_slot',
            ]));
        } else {
            $validated['doubt_sessions_per_week'] = null;
            $validated['doubt_hours_per_week'] = null;
            $validated['proposed_hourly_rate_inr'] = null;
            $validated['agreed_to_mentoring_program'] = false;
        }

        $validated['teaches_english_medium'] = (bool) ($validated['teaches_english_medium'] ?? true);
        $validated['teaches_hindi_medium'] = (bool) ($validated['teaches_hindi_medium'] ?? false);
        $validated['country'] = $validated['country'] ?? 'India';

        if (! $validated['teaches_english_medium'] && ! $validated['teaches_hindi_medium']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'teaches_english_medium' => 'Select at least one language: English and/or Hindi.',
            ]);
        }

        return $validated;
    }
}
