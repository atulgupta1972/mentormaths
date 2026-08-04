<?php

namespace App\Services;

use App\Models\TeacherRegistrationRequest;
use App\Models\User;
use App\Support\TeacherRegistrationMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TeacherRegistrationService
{
    public function __construct(private UserGroupService $userGroupService) {}

    public function sendCounterOffer(
        TeacherRegistrationRequest $request,
        int $counterHourlyRateInr,
        ?string $message,
        int $adminUserId,
    ): TeacherRegistrationRequest {
        if (! $request->canSendCounterOffer()) {
            throw new \InvalidArgumentException('Cannot send a counter offer for this application.');
        }

        $request->update([
            'status' => TeacherRegistrationRequest::STATUS_COUNTER_OFFERED,
            'counter_hourly_rate_inr' => $counterHourlyRateInr,
            'counter_offer_message' => $message,
            'counter_offer_token' => (string) Str::uuid(),
            'counter_offer_sent_at' => now(),
            'offer_responded_at' => null,
            'offer_response' => null,
            'reviewed_by' => $adminUserId,
        ]);

        TeacherRegistrationMailer::sendCounterOffer($request->fresh());

        return $request->fresh();
    }

    public function respondToOffer(TeacherRegistrationRequest $request, string $response): TeacherRegistrationRequest
    {
        if (! $request->canRespondToOffer()) {
            throw new \InvalidArgumentException('This offer is no longer open for a response.');
        }

        if (! in_array($response, [
            TeacherRegistrationRequest::OFFER_ACCEPTED,
            TeacherRegistrationRequest::OFFER_DECLINED,
        ], true)) {
            throw new \InvalidArgumentException('Invalid offer response.');
        }

        $status = $response === TeacherRegistrationRequest::OFFER_ACCEPTED
            ? TeacherRegistrationRequest::STATUS_OFFER_ACCEPTED
            : TeacherRegistrationRequest::STATUS_OFFER_DECLINED;

        $request->update([
            'status' => $status,
            'offer_response' => $response,
            'offer_responded_at' => now(),
        ]);

        TeacherRegistrationMailer::notifyAdminOfferResponse($request->fresh());

        return $request->fresh();
    }

    public function approve(TeacherRegistrationRequest $request, int $adminUserId, ?string $adminNotes = null): User
    {
        if (! $request->canApprove()) {
            throw new \InvalidArgumentException('This application cannot be approved in its current state.');
        }

        if (User::query()->where('email', $request->email)->exists()) {
            throw new \InvalidArgumentException('A user with this email already exists.');
        }

        return DB::transaction(function () use ($request, $adminUserId, $adminNotes) {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->getAttributes()['password'],
                'role' => User::ROLE_TEACHER,
                'mobile' => $request->mobile,
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            $this->userGroupService->attachGroupByCode($user, User::ROLE_TEACHER);

            $request->update([
                'status' => TeacherRegistrationRequest::STATUS_APPROVED,
                'admin_notes' => $adminNotes,
                'reviewed_by' => $adminUserId,
                'reviewed_at' => now(),
                'user_id' => $user->id,
            ]);

            TeacherRegistrationMailer::sendApproved($request->fresh());

            return $user;
        });
    }

    public function reject(TeacherRegistrationRequest $request, int $adminUserId, ?string $adminNotes = null): TeacherRegistrationRequest
    {
        if (in_array($request->status, [
            TeacherRegistrationRequest::STATUS_APPROVED,
            TeacherRegistrationRequest::STATUS_REJECTED,
        ], true)) {
            throw new \InvalidArgumentException('This application has already been finalized.');
        }

        $request->update([
            'status' => TeacherRegistrationRequest::STATUS_REJECTED,
            'admin_notes' => $adminNotes,
            'reviewed_by' => $adminUserId,
            'reviewed_at' => now(),
        ]);

        return $request->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeForAdmin(TeacherRegistrationRequest $request, $boards, $gradeLevels): array
    {
        $boardMap = collect($boards)->keyBy('id');
        $gradeMap = collect($gradeLevels)->keyBy('id');

        $mapGrades = fn (?array $ids) => collect($ids ?? [])
            ->map(fn ($id) => $gradeMap->get($id)?->name ?? "Class {$id}")
            ->filter()
            ->values()
            ->all();

        return [
            ...$request->toArray(),
            'status_label' => TeacherRegistrationRequest::statusLabel($request->status),
            'board_labels' => collect($request->board_ids ?? [])
                ->map(fn ($id) => $boardMap->get($id)?->name ?? $id)
                ->values()
                ->all(),
            'teaching_class_labels' => $mapGrades($request->teaching_grade_level_ids),
            'content_class_labels' => $mapGrades($request->content_grade_level_ids),
            'agreed_hourly_rate_inr' => $request->agreedHourlyRateInr(),
            'age' => $request->age(),
            'platform_usage_scope_label' => $request->platformUsageScopeLabel(),
            'current_tool_feature_labels' => $request->currentToolFeatureLabels(),
            'resume_download_url' => $request->resume_path
                ? route('admin.teacher-registrations.resume', $request)
                : null,
            'offer_url' => $request->counter_offer_token
                ? route('teacher-registration.offer', $request->counter_offer_token)
                : null,
        ];
    }
}
