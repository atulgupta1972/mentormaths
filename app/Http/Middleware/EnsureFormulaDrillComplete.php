<?php

namespace App\Http\Middleware;

use App\Services\FormulaDrillSessionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFormulaDrillComplete
{
    public function __construct(
        private FormulaDrillSessionService $sessionService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isAdmin() || ! $user->isStudent()) {
            return $next($request);
        }

        $student = $user->student;

        if (! $student) {
            return $next($request);
        }

        if ($this->isExemptRoute($request)) {
            return $next($request);
        }

        if ($this->sessionService->gatePassed($student)) {
            return $next($request);
        }

        if ($request->routeIs('student.formula-drill.*')) {
            return $next($request);
        }

        return redirect()->route('student.formula-drill.show');
    }

    private function isExemptRoute(Request $request): bool
    {
        return $request->routeIs(
            'student.formula-drill.*',
            'logout',
            'verification.*',
        );
    }
}
