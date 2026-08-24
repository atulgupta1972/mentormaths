<?php

namespace App\Http\Requests\Auth;

use App\Services\AccessCodeService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $accessCodes = app(AccessCodeService::class);
        $login = trim((string) $this->input('email'));
        $password = (string) $this->input('password', '');

        $authenticated = false;

        if ($accessCodes->looksLikeCode($login)) {
            $accessCode = $accessCodes->findUsableByCode($login);
            if ($accessCode?->user) {
                Auth::login($accessCode->user, $this->boolean('remember'));
                $authenticated = true;
            }
        } elseif (filled($password) && Auth::attempt(['email' => $login, 'password' => $password], $this->boolean('remember'))) {
            $authenticated = true;
        } elseif (filled($password) && $accessCodes->looksLikeCode($password)) {
            $accessCode = $accessCodes->findUsableByCode($password);
            if ($accessCode?->user && strcasecmp((string) $accessCode->user->email, $login) === 0) {
                Auth::login($accessCode->user, $this->boolean('remember'));
                $authenticated = true;
            }
        }

        if (! $authenticated) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        $user = Auth::user();

        if ($user && ! $user->isActiveAccount()) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'Your account is inactive. Please contact the administrator.',
            ]);
        }

        try {
            $accessCodes->assertUserMayLogin($user);
        } catch (ValidationException $e) {
            Auth::logout();
            throw $e;
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
