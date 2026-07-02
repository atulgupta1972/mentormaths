<?php

namespace App\Http\Middleware;

use App\Services\AdminGradeContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                'isAdmin' => $user?->isAdmin() ?? false,
                'isStudent' => $user?->isStudent() ?? false,
            ],
            'gradeContext' => fn () => $user?->isAdmin()
                ? app(AdminGradeContext::class)->sharedPayload($request)
                : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'generated_login' => fn () => $request->session()->get('generated_login'),
                'email_sent' => fn () => $request->session()->get('email_sent'),
                'promotion_errors' => fn () => $request->session()->get('promotion_errors'),
                'import_rows' => fn () => $request->session()->get('import_rows'),
            ],
        ];
    }
}
