<?php

namespace App\Support;

use App\Models\RegistrationRequest;
use App\Models\Student;
use App\Models\User;

class StudentIdentity
{
    public static function normalizeName(string $name): string
    {
        $trimmed = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return mb_strtolower($trimmed);
    }

    public static function normalizeMobile(?string $mobile): ?string
    {
        if ($mobile === null || $mobile === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $mobile) ?? '';

        if (strlen($digits) < 10) {
            return null;
        }

        return substr($digits, -10);
    }

    public static function isCheckable(?string $name, ?string $mobile): bool
    {
        return filled($name) && self::normalizeMobile($mobile) !== null;
    }

    public static function findExistingStudent(string $name, ?string $mobile): ?Student
    {
        if (! self::isCheckable($name, $mobile)) {
            return null;
        }

        $normalizedName = self::normalizeName($name);
        $normalizedMobile = self::normalizeMobile($mobile);

        return Student::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->get()
            ->first(fn (Student $student) => self::normalizeMobile($student->student_mobile) === $normalizedMobile);
    }

    public static function findPendingRequest(string $name, ?string $mobile, ?int $ignoreRequestId = null): ?RegistrationRequest
    {
        if (! self::isCheckable($name, $mobile)) {
            return null;
        }

        $normalizedName = self::normalizeName($name);
        $normalizedMobile = self::normalizeMobile($mobile);

        return RegistrationRequest::query()
            ->where('status', RegistrationRequest::STATUS_PENDING)
            ->when($ignoreRequestId, fn ($query) => $query->where('id', '!=', $ignoreRequestId))
            ->whereRaw('LOWER(TRIM(student_name)) = ?', [$normalizedName])
            ->get()
            ->first(fn (RegistrationRequest $request) => self::normalizeMobile($request->student_mobile) === $normalizedMobile);
    }

    public static function canReuseStudentProfile(Student $student): bool
    {
        $student->loadMissing('user');

        if (! $student->user) {
            return true;
        }

        return ! $student->user->isActiveAccount();
    }

    public static function hasDuplicate(string $name, ?string $mobile, ?int $ignoreRequestId = null): bool
    {
        $existing = self::findExistingStudent($name, $mobile);

        if ($existing !== null && ! self::canReuseStudentProfile($existing)) {
            return true;
        }

        return self::findPendingRequest($name, $mobile, $ignoreRequestId) !== null;
    }

    public static function releaseInactiveLoginForEmail(string $email): void
    {
        User::query()
            ->where('email', $email)
            ->where('is_active', false)
            ->each(function (User $user) {
                Student::query()->where('user_id', $user->id)->update(['user_id' => null]);
                $user->delete();
            });
    }
}
