<?php

namespace App\Http\Middleware;

use App\Services\BasicsDrillSessionService;
use App\Services\FormulaDrillSessionService;
use App\Services\MensurationMatchService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureBasicsDrillComplete
{
    public function __construct(
        private BasicsDrillSessionService $basicsService,
        private FormulaDrillSessionService $formulaService,
        private MensurationMatchService $mensuration,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isAdmin() || ! $user->isStudent()) {
            return $next($request);
        }

        if (! Schema::hasTable('basics_drill_sessions')) {
            return $next($request);
        }

        $student = $user->student;

        if (! $student) {
            return $next($request);
        }

        if ($this->isExemptRoute($request)) {
            return $next($request);
        }

        if (! $this->formulaService->gatePassed($student)) {
            return $next($request);
        }

        if (! $this->mensuration->gatePassed($student)) {
            return $next($request);
        }

        if ($this->basicsService->gatePassed($student)) {
            return $next($request);
        }

        if ($request->routeIs('student.basics-drill.*')) {
            return $next($request);
        }

        return redirect()->route('student.basics-drill.show');
    }

    private function isExemptRoute(Request $request): bool
    {
        return $request->routeIs(
            'student.formula-drill.*',
            'student.mensuration-match.*',
            'student.basics-drill.*',
            'student.school-study-plan.*',
            'student.worksheets.self-assign',
            'student.worksheets.correction-practice',
            'logout',
            'verification.*',
        );
    }
}
