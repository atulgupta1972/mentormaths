<?php

namespace App\Support;

use App\Mail\AccessCodeIssued;
use App\Mail\TrialSignupAdmin;
use App\Models\AccessCode;
use App\Models\User;
use App\Support\WhatsApp\WhatsAppSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AccessCodeMailer
{
    public static function sendIssued(
        AccessCode $accessCode,
        string $loginEmail,
        string $plainCode,
        string $recipientName,
        ?string $extraEmail = null,
        string|array|null $extraMobile = null,
    ): void {
        $emails = collect([$loginEmail, $extraEmail])
            ->filter(fn ($e) => AssignmentMailer::isDeliverableEmail($e))
            ->unique(fn ($e) => strtolower((string) $e))
            ->values();

        foreach ($emails as $email) {
            try {
                Mail::to($email)->send(new AccessCodeIssued(
                    $accessCode,
                    $loginEmail,
                    $plainCode,
                    $recipientName,
                ));
            } catch (\Throwable $e) {
                Log::error('Failed to send access code email.', [
                    'access_code_id' => $accessCode->id,
                    'email' => $email,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $mobiles = collect([$accessCode->mobile])
            ->merge(is_array($extraMobile) ? $extraMobile : [$extraMobile])
            ->filter(fn ($m) => filled($m))
            ->unique()
            ->values();

        $message = self::whatsAppMessage($loginEmail, $plainCode, $accessCode);

        foreach ($mobiles as $mobile) {
            WhatsAppSender::sendText('access_code', $mobile, $message, [
                'access_code_id' => $accessCode->id,
            ]);
        }
    }

    public static function notifyAdmin(AccessCode $accessCode, string $summary): void
    {
        $adminEmail = RegistrationMailer::resolveAdminNotifyEmail();

        if (! $adminEmail) {
            Log::warning('No admin email for trial signup notification.', [
                'access_code_id' => $accessCode->id,
            ]);

            return;
        }

        try {
            Mail::to($adminEmail)->send(new TrialSignupAdmin($accessCode, $summary));
        } catch (\Throwable $e) {
            Log::error('Failed to send trial signup admin email.', [
                'access_code_id' => $accessCode->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function resolveMentorEmail(\App\Models\Student $student): ?string
    {
        $student->loadMissing('coachingClassTeacher');

        if ($student->enrollment_source === \App\Support\EnrollmentSource::COACHING) {
            $teacherEmail = $student->coachingClassTeacher?->email;
            if (AssignmentMailer::isDeliverableEmail($teacherEmail)) {
                return $teacherEmail;
            }

            if ($student->coachingClassTeacher?->user_id) {
                $email = User::query()->whereKey($student->coachingClassTeacher->user_id)->value('email');
                if (AssignmentMailer::isDeliverableEmail($email)) {
                    return $email;
                }
            }
        }

        if (
            $student->notify_parent1_email
            && AssignmentMailer::isDeliverableEmail($student->parent1_email)
        ) {
            return $student->parent1_email;
        }

        if (AssignmentMailer::isDeliverableEmail($student->parent1_email)) {
            return $student->parent1_email;
        }

        return null;
    }

    private static function whatsAppMessage(string $loginEmail, string $plainCode, AccessCode $accessCode): string
    {
        $expires = $accessCode->expires_at?->timezone(config('app.timezone'))->format('d M Y') ?? '';

        return "Mentor Maths access code (tcode): {$plainCode}\n"
            ."Login: ".route('login')."\n"
            ."Email: {$loginEmail}\n"
            ."Use your tcode as the password.\n"
            ."Valid until: {$expires}";
    }
}
