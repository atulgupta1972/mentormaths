<?php

namespace App\Http\Middleware;

use App\Services\FormulaDrillSessionService;
use App\Services\MensurationMatchService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class EnsureMensurationMatchComplete
{
    public function __construct(
        private MensurationMatchService $mensuration,
        private FormulaDrillSessionService $formulaService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->isAdmin() || ! $user->isStudent()) {
            return $next($request);
        }

        if (! Schema::hasTable('mensuration_match_sessions')) {
            return $next($request);
        }

        $student = $user->student;

        if (! $student) {
            return $next($request);
        }

        if ($this->isExemptRoute($request)) {
            return $next($request);
        }

        // Formula gate runs first; if formulas are still open, let that middleware redirect.
        if (! $this->formulaService->gatePassed($student)) {
            return $next($request);
        }

        if ($this->mensuration->gatePassed($student)) {
            return $next($request);
        }

        if ($request->routeIs('student.mensuration-match.*')) {
            return $next($request);
        }

        return redirect()->route('student.mensuration-match.show');
    }

    private function isExemptRoute(Request $request): bool
    {
        return $request->routeIs(
            'student.formula-drill.*',
            'student.mensuration-match.*',
            'student.school-study-plan.*',
            'logout',
            'verification.*',
        );
    }
}
