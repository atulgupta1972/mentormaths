<?php

namespace App\Support;

use App\Mail\NewRegistrationRequestAdmin;
use App\Mail\RegistrationApproved;
use App\Mail\RegistrationRequestReceived;
use App\Models\RegistrationRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RegistrationMailer
{
    public static function sendRequestReceived(RegistrationRequest $registrationRequest): void
    {
        if (! $registrationRequest->email) {
            return;
        }

        try {
            Mail::to($registrationRequest->email)
                ->send(new RegistrationRequestReceived($registrationRequest));
        } catch (\Throwable $e) {
            Log::error('Failed to send registration confirmation email.', [
                'registration_request_id' => $registrationRequest->id,
                'email' => $registrationRequest->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function notifyAdmin(RegistrationRequest $registrationRequest): void
    {
        $adminEmail = config('mail.registration_notify');

        if (! $adminEmail) {
            return;
        }

        try {
            Mail::to($adminEmail)
                ->send(new NewRegistrationRequestAdmin($registrationRequest));
        } catch (\Throwable $e) {
            Log::error('Failed to send admin registration notification.', [
                'registration_request_id' => $registrationRequest->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function sendApproved(
        RegistrationRequest $registrationRequest,
        string $loginEmail,
        string $loginPassword,
    ): bool {
        if (! str_contains($loginEmail, '@') || str_ends_with($loginEmail, '@mathsfoundation.local')) {
            return false;
        }

        try {
            Mail::to($loginEmail)
                ->send(new RegistrationApproved($registrationRequest, $loginEmail, $loginPassword));

            return true;
        } catch (\Throwable $e) {
            Log::error('Failed to send registration approval email.', [
                'registration_request_id' => $registrationRequest->id,
                'email' => $loginEmail,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
