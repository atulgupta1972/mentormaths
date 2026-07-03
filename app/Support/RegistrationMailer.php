<?php

namespace App\Support;

use App\Mail\NewRegistrationRequestAdmin;
use App\Mail\RegistrationApproved;
use App\Mail\RegistrationRequestReceived;
use App\Models\RegistrationRequest;
use App\Models\User;
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
        $adminEmail = self::resolveAdminNotifyEmail();

        if (! $adminEmail) {
            Log::warning('No admin email configured for registration notifications.', [
                'registration_request_id' => $registrationRequest->id,
            ]);

            return;
        }

        try {
            Mail::to($adminEmail)
                ->send(new NewRegistrationRequestAdmin($registrationRequest));
        } catch (\Throwable $e) {
            Log::error('Failed to send admin registration notification.', [
                'registration_request_id' => $registrationRequest->id,
                'admin_email' => $adminEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function sendApproved(
        RegistrationRequest $registrationRequest,
        string $loginEmail,
        ?string $loginPassword = null,
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

    public static function resolveAdminNotifyEmail(): ?string
    {
        $configured = config('mail.registration_notify');

        if (filled($configured)) {
            return $configured;
        }

        return User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('email');
    }
}
