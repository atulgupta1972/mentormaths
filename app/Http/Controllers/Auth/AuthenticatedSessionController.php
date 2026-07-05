<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();
        $intended = $request->session()->get('url.intended');

        if ($intended && $this->shouldSkipIntendedUrl($user, $intended)) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Drop post-login redirects to admin-only URLs for non-admin accounts.
     */
    private function shouldSkipIntendedUrl($user, string $url): bool
    {
        if ($user->isAdmin()) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';

        $adminOnlyPrefixes = [
            '/admin/registration-requests',
            '/admin/users',
            '/admin/groups',
            '/admin/students',
            '/admin/academic-years',
            '/admin/chapter-heads',
            '/admin/practice-sets',
            '/admin/set-assignments',
            '/admin/exam-plans',
            '/admin/questions/create',
        ];

        foreach ($adminOnlyPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
                return true;
            }
        }

        if ($path === '/admin/syllabus' || str_starts_with($path, '/admin/syllabus/import')) {
            return true;
        }

        if (preg_match('#^/admin/syllabus/\d+/(import|import-preview|rows|carry-forward)#', $path)) {
            return true;
        }

        if (preg_match('#^/admin/questions/\d+/edit$#', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
