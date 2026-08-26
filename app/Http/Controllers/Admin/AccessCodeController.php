<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessCode;
use App\Services\AccessCodeService;
use App\Support\AccessCodeMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccessCodeController extends Controller
{
    public function index(Request $request): Response
    {
        $type = $request->string('type')->toString();
        $status = $request->string('status')->toString();

        $codes = AccessCode::query()
            ->with(['user:id,name,email', 'student:id,name', 'coachingClass:id,name'])
            ->when($type !== '', fn ($q) => $q->where('type', $type))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Admin/AccessCodes/Index', [
            'codes' => $codes,
            'filters' => [
                'type' => $type,
                'status' => $status,
            ],
            'trialDays' => (int) config('access.trial_days', 15),
            'types' => [AccessCode::TYPE_STUDENT, AccessCode::TYPE_MENTOR],
            'statuses' => [
                AccessCode::STATUS_ACTIVE,
                AccessCode::STATUS_EXPIRED,
                AccessCode::STATUS_REVOKED,
            ],
        ]);
    }

    public function extend(Request $request, AccessCode $accessCode, AccessCodeService $accessCodes): RedirectResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        $accessCodes->extend($accessCode, $validated['days'] ?? null);

        return back()->with('success', "Access code {$accessCode->code} extended.");
    }

    public function resend(AccessCode $accessCode): RedirectResponse
    {
        $sent = AccessCodeMailer::resendIssued($accessCode);

        $to = $accessCode->email ?: $accessCode->user?->email ?: 'the recipient';

        return back()->with(
            $sent ? 'success' : 'error',
            $sent
                ? "Welcome email resent to {$to}."
                : 'Could not resend email — check the address on the access code or mail settings.',
        );
    }
}
