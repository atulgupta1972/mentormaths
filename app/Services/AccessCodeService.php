<?php

namespace App\Services;

use App\Models\AccessCode;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccessCodeService
{
    public function trialDays(): int
    {
        return max(1, (int) config('access.trial_days', 15));
    }

    public function looksLikeCode(?string $value): bool
    {
        if (! filled($value)) {
            return false;
        }

        $prefix = preg_quote((string) config('access.code_prefix', 'MM'), '/');
        $len = (int) config('access.code_length', 6);

        return (bool) preg_match('/^'.$prefix.'-[A-Z0-9]{'.$len.'}$/i', trim($value));
    }

    public function normalize(?string $value): string
    {
        return Str::upper(trim((string) $value));
    }

    public function generateUniqueCode(): string
    {
        $prefix = (string) config('access.code_prefix', 'MM');
        $len = (int) config('access.code_length', 6);
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        for ($i = 0; $i < 40; $i++) {
            $body = '';
            for ($j = 0; $j < $len; $j++) {
                $body .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $code = $prefix.'-'.$body;

            if (! AccessCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new \RuntimeException('Unable to generate a unique access code.');
    }

    /**
     * @param  array{
     *     type: string,
     *     user: User,
     *     email?: ?string,
     *     mobile?: ?string,
     *     student_id?: ?int,
     *     coaching_class_id?: ?int,
     *     coaching_class_teacher_id?: ?int,
     *     notes?: ?string,
     * }  $payload
     */
    public function issue(array $payload): AccessCode
    {
        $generatedAt = now();

        return AccessCode::query()->create([
            'code' => $this->generateUniqueCode(),
            'type' => $payload['type'],
            'status' => AccessCode::STATUS_ACTIVE,
            'user_id' => $payload['user']->id,
            'student_id' => $payload['student_id'] ?? null,
            'coaching_class_id' => $payload['coaching_class_id'] ?? null,
            'coaching_class_teacher_id' => $payload['coaching_class_teacher_id'] ?? null,
            'email' => $payload['email'] ?? $payload['user']->email,
            'mobile' => $payload['mobile'] ?? $payload['user']->mobile,
            'generated_at' => $generatedAt,
            'expires_at' => $generatedAt->copy()->addDays($this->trialDays()),
            'notes' => $payload['notes'] ?? null,
        ]);
    }

    public function findUsableByCode(string $code): ?AccessCode
    {
        $normalized = $this->normalize($code);

        $accessCode = AccessCode::query()
            ->with('user')
            ->where('code', $normalized)
            ->where('status', '!=', AccessCode::STATUS_REVOKED)
            ->first();

        if (! $accessCode) {
            return null;
        }

        $accessCode->markExpiredIfNeeded();
        $accessCode->refresh();

        if (! $accessCode->isActive()) {
            return null;
        }

        return $accessCode;
    }

    public function latestForUser(User $user): ?AccessCode
    {
        return AccessCode::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', AccessCode::STATUS_REVOKED)
            ->orderByDesc('id')
            ->first();
    }

    public function assertUserMayLogin(User $user): void
    {
        if ($user->isAdmin()) {
            return;
        }

        $accessCode = $this->latestForUser($user);

        if (! $accessCode) {
            return;
        }

        $accessCode->markExpiredIfNeeded();
        $accessCode->refresh();

        if ($accessCode->isExpired() || ! $accessCode->isActive()) {
            throw ValidationException::withMessages([
                'email' => 'Your access code (tcode) has expired. Contact Mentor Maths to extend access.',
            ]);
        }
    }

    public function extend(AccessCode $accessCode, ?int $days = null): AccessCode
    {
        $days = $days ?? $this->trialDays();
        $from = $accessCode->expires_at?->isFuture()
            ? $accessCode->expires_at->copy()
            : now();

        $accessCode->update([
            'status' => AccessCode::STATUS_ACTIVE,
            'expires_at' => $from->addDays($days),
            'extended_at' => now(),
            'extension_days_total' => ($accessCode->extension_days_total ?? 0) + $days,
        ]);

        return $accessCode->fresh();
    }
}
