<?php

namespace App\Support;

use App\Mail\TeacherRegistrationApproved;
use App\Mail\TeacherRegistrationCounterOffer;
use App\Mail\TeacherRegistrationOfferResponseAdmin;
use App\Mail\TeacherRegistrationReceived;
use App\Mail\TeacherRegistrationReceivedAdmin;
use App\Models\TeacherRegistrationRequest;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class TeacherRegistrationMailer
{
    public static function sendRequestReceived(TeacherRegistrationRequest $request): void
    {
        try {
            Mail::to($request->email)->send(new TeacherRegistrationReceived($request));
        } catch (\Throwable $e) {
            Log::error('Failed to send teacher registration confirmation email.', [
                'teacher_registration_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdmin(TeacherRegistrationRequest $request): void
    {
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->send(new TeacherRegistrationReceivedAdmin($request));
        } catch (\Throwable $e) {
            Log::error('Failed to send admin teacher registration notification.', [
                'teacher_registration_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function sendCounterOffer(TeacherRegistrationRequest $request): void
    {
        try {
            Mail::to($request->email)->send(new TeacherRegistrationCounterOffer($request));
        } catch (\Throwable $e) {
            Log::error('Failed to send teacher counter offer email.', [
                'teacher_registration_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdminOfferResponse(TeacherRegistrationRequest $request): void
    {
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)->send(new TeacherRegistrationOfferResponseAdmin($request));
        } catch (\Throwable $e) {
            Log::error('Failed to send teacher offer response admin notification.', [
                'teacher_registration_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function sendApproved(TeacherRegistrationRequest $request): bool
    {
        try {
            Mail::to($request->email)->send(new TeacherRegistrationApproved($request));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send teacher approval email.', [
                'teacher_registration_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
