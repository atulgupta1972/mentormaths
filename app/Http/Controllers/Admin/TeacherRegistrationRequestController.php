<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\GradeLevel;
use App\Models\TeacherRegistrationRequest;
use App\Services\TeacherRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class TeacherRegistrationRequestController extends Controller
{
    public function __construct(private TeacherRegistrationService $service) {}

    public function index(Request $request): Response
    {
        $status = $request->string('status')->toString();

        $applications = TeacherRegistrationRequest::query()
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Admin/TeacherRegistrationRequests/Index', [
            'applications' => $applications,
            'filters' => ['status' => $status],
            'statuses' => [
                TeacherRegistrationRequest::STATUS_PENDING,
                TeacherRegistrationRequest::STATUS_COUNTER_OFFERED,
                TeacherRegistrationRequest::STATUS_OFFER_ACCEPTED,
                TeacherRegistrationRequest::STATUS_OFFER_DECLINED,
                TeacherRegistrationRequest::STATUS_APPROVED,
                TeacherRegistrationRequest::STATUS_REJECTED,
            ],
        ]);
    }

    public function show(TeacherRegistrationRequest $teacherRegistration): Response
    {
        $teacherRegistration->load(['reviewer:id,name', 'user:id,name,email']);

        $boards = Board::query()->orderBy('name')->get(['id', 'name', 'code']);
        $gradeLevels = GradeLevel::query()
            ->whereBetween('sort_order', [5, 12])
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return Inertia::render('Admin/TeacherRegistrationRequests/Show', [
            'application' => $this->service->serializeForAdmin($teacherRegistration, $boards, $gradeLevels),
            'shareLinks' => [
                'login' => route('login'),
            ],
        ]);
    }

    public function sendCounterOffer(Request $request, TeacherRegistrationRequest $teacherRegistration): RedirectResponse
    {
        $validated = $request->validate([
            'counter_hourly_rate_inr' => ['required', 'integer', 'min:100', 'max:100000'],
            'counter_offer_message' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->sendCounterOffer(
                $teacherRegistration,
                (int) $validated['counter_hourly_rate_inr'],
                $validated['counter_offer_message'] ?? null,
                $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Counter offer sent by email. The mentor must accept before you can approve.');
    }

    public function downloadResume(TeacherRegistrationRequest $teacherRegistration)
    {
        abort_unless($teacherRegistration->resume_path, 404);
        abort_unless(Storage::disk('public')->exists($teacherRegistration->resume_path), 404);

        return Storage::disk('public')->download(
            $teacherRegistration->resume_path,
            $teacherRegistration->resume_original_name ?? 'resume.pdf',
        );
    }

    public function approve(Request $request, TeacherRegistrationRequest $teacherRegistration): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->approve(
                $teacherRegistration,
                $request->user()->id,
                $validated['admin_notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.teacher-registrations.show', $teacherRegistration)
            ->with('success', 'Mentor application approved. They can log in with the email and password they registered with.');
    }

    public function reject(Request $request, TeacherRegistrationRequest $teacherRegistration): RedirectResponse
    {
        $validated = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->service->reject(
                $teacherRegistration,
                $request->user()->id,
                $validated['admin_notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.teacher-registrations.index')
            ->with('success', 'Mentor application rejected.');
    }
}
