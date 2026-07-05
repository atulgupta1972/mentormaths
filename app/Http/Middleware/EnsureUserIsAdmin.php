<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isAdmin()) {
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
