<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isAdmin()) {
            return $next($request);
        }

        if ($user?->isMentor() && $request->routeIs([
            'admin.school-study-plan.*',
            'admin.grade-context.update',
            'admin.questions.coverage',
        ])) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Admin access required.');
        }

        return redirect()
            ->route('dashboard')
            ->with('warning', 'That page is for administrators only.');
    }
}
