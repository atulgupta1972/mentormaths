<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureContentUploader
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || (! $user->isContentUploader() && ! $user->isAdmin())) {
            abort(403, 'Content uploader access required.');
        }

        return $next($request);
    }
}
