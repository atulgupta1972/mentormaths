<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\RegistrationRequest;
use App\Support\RegistrationMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'academicYear' => $activeYear->only(['id', 'name', 'starts_on', 'ends_on']),
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
            'student_mobile' => ['nullable', 'string', 'max:15'],
            'parent1_name' => ['required', 'string', 'max:255'],
            'parent1_mobile' => ['required', 'string', 'max:15'],
            'parent2_name' => ['nullable', 'string', 'max:255'],
            'parent2_mobile' => ['nullable', 'string', 'max:15'],
            'school_name' => ['required', 'string', 'max:255'],
            'board_id' => ['required', 'exists:boards,id'],
            'grade_level_id' => ['required', 'exists:grade_levels,id'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $registrationRequest = RegistrationRequest::create([
            ...$validated,
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
