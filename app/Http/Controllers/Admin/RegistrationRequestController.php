<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\RegistrationMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $requests = RegistrationRequest::query()
            ->with(['academicYear:id,name', 'board:id,code,name', 'gradeLevel:id,name'])
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/RegistrationRequests/Index', [
            'requests' => $requests,
            'filters' => ['status' => $status],
            'statuses' => [
                RegistrationRequest::STATUS_PENDING,
                RegistrationRequest::STATUS_APPROVED,
                RegistrationRequest::STATUS_REJECTED,
            ],
        ]);
    }

    public function show(RegistrationRequest $registrationRequest): Response
    {
        $registrationRequest->load([
            'academicYear',
            'board',
            'gradeLevel',
            'reviewer:id,name',
            'student',
        ]);

        return Inertia::render('Admin/RegistrationRequests/Show', [
            'registrationRequest' => $registrationRequest,
        ]);
    }

    public function approve(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        if (! $registrationRequest->isPending()) {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $generatedPassword = null;
        $loginEmail = null;

        DB::transaction(function () use ($registrationRequest, $request, $validated, &$generatedPassword, &$loginEmail) {
            $student = Student::create([
                'name' => $registrationRequest->student_name,
                'date_of_birth' => $registrationRequest->date_of_birth,
                'student_mobile' => $registrationRequest->student_mobile,
                'parent1_name' => $registrationRequest->parent1_name,
                'parent1_mobile' => $registrationRequest->parent1_mobile,
                'parent2_name' => $registrationRequest->parent2_name,
                'parent2_mobile' => $registrationRequest->parent2_mobile,
                'school_name' => $registrationRequest->school_name,
                'email' => $registrationRequest->email,
            ]);

            StudentEnrollment::create([
                'student_id' => $student->id,
                'academic_year_id' => $registrationRequest->academic_year_id,
                'board_id' => $registrationRequest->board_id,
                'grade_level_id' => $registrationRequest->grade_level_id,
                'school_name' => $registrationRequest->school_name,
                'status' => StudentEnrollment::STATUS_ACTIVE,
            ]);

            $generatedPassword = Str::password(12);
            $email = $registrationRequest->email
                ?? 'student.'.$student->id.'@mathsfoundation.local';

            $loginEmail = $email;

            $user = User::create([
                'name' => $registrationRequest->student_name,
                'email' => $email,
                'password' => $generatedPassword,
                'role' => User::ROLE_STUDENT,
                'mobile' => $registrationRequest->student_mobile ?? $registrationRequest->parent1_mobile,
                'email_verified_at' => $registrationRequest->email ? now() : null,
            ]);

            $student->update(['user_id' => $user->id]);

            $registrationRequest->update([
                'status' => RegistrationRequest::STATUS_APPROVED,
                'student_id' => $student->id,
                'admin_notes' => $validated['admin_notes'] ?? null,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);
        });

        $emailSent = RegistrationMailer::sendApproved(
            $registrationRequest->fresh(),
            $loginEmail,
            $generatedPassword,
        );

        return redirect()
            ->route('admin.registration-requests.show', $registrationRequest)
            ->with('success', 'Registration approved.')
            ->with('generated_login', [
                'email' => $loginEmail,
                'password' => $generatedPassword,
            ])
            ->with('email_sent', $emailSent);
    }

    public function reject(Request $request, RegistrationRequest $registrationRequest): RedirectResponse
    {
        if (! $registrationRequest->isPending()) {
            return back()->with('error', 'This request has already been reviewed.');
        }

        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $registrationRequest->update([
            'status' => RegistrationRequest::STATUS_REJECTED,
            'admin_notes' => $validated['admin_notes'] ?? null,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return redirect()
            ->route('admin.registration-requests.index')
            ->with('success', 'Registration request rejected.');
    }
}
