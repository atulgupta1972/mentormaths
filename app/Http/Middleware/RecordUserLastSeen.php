<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class RecordUserLastSeen
{
    /** Avoid writing on every asset/request flood. */
    private const THROTTLE_SECONDS = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $this->supportsLastSeen()) {
            try {
                $lastSeen = $user->last_seen_at;
                $stale = ! $lastSeen || $lastSeen->lt(now()->subSeconds(self::THROTTLE_SECONDS));

                if ($stale) {
                    // Quiet update — do not bump updated_at.
                    $user->forceFill(['last_seen_at' => now()])->saveQuietly();
                }
            } catch (\Throwable) {
                // Never block student requests if presence tracking fails.
            }
        }

        return $next($request);
    }

    private function supportsLastSeen(): bool
    {
        static $supported = null;

        if ($supported === null) {
            try {
                $supported = Schema::hasColumn('users', 'last_seen_at');
            } catch (\Throwable) {
                $supported = false;
            }
        }

        return $supported;
    }
}
