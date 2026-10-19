<?php

namespace App\Http\Middleware;

use App\Services\ChapterTermMapGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureChapterTermsMapped
{
    public function __construct(
        private ChapterTermMapGate $gate,
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

        $enrollment = $student->currentEnrollment();
        $status = $this->gate->statusForEnrollment($enrollment);

        return redirect()
            ->route('student.school-study-plan.show')
            ->with('error', $status['message'] ?? $this->gate->blockingMessage());
    }

    private function isExemptRoute(Request $request): bool
    {
        return $request->routeIs(
            'student.exams.*',
            'student.exam-plans.*',
            'student.school-study-plan.*',
            'student.class-coverage.*',
            'profile.*',
            'logout',
            'verification.*',
        );
    }
}
