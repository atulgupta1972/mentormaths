<?php

namespace App\Rules;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniqueStudentLoginEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::query()->where('email', $value)->first();

        if (! $user) {
            return;
        }

        if ($user->isActiveAccount()) {
            $fail('This login email is already registered or has a pending request. Try another email or log in.');
        }
    }
}
