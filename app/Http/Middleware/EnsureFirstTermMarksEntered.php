<?php

namespace App\Http\Middleware;

use App\Services\FirstTermMarksGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFirstTermMarksEntered
{
    public function __construct(
        private FirstTermMarksGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isAdmin() || ! $user->isStudent()) {
            return $next($request);
        }

        if (! $this->gate->isRequired()) {
            return $next($request);
        }

        if ($this->isExemptRoute($request)) {
            return $next($request);
        }

        $student = $user->student;
        if (! $student || $this->gate->gatePassed($student)) {
            return $next($request);
        }

        return redirect()
            ->route('student.exams.index')
            ->with('error', $this->gate->blockingMessage($student));
    }

    private function isExemptRoute(Request $request): bool
    {
        return $request->routeIs(
            'student.exams.*',
            'student.exam-plans.*',
            'profile.*',
            'logout',
            'verification.*',
        );
    }
}
