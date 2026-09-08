<?php

namespace App\Http\Middleware;

use App\Services\ContentUploaderDashboardService;
use App\Support\ContentOperationsMailer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploaderGeminiCheckComplete
{
    /**
     * New upload / import routes blocked while Gemini is pending.
     * Viewing uploaded chapters and working Gemini on existing tasks stay allowed.
     *
     * @var list<string>
     */
    private const BLOCKED_WHILE_PENDING = [
        'content.chapters.append-mcq',
        'content.chapters.append-mcq-zip',
    ];

    public function __construct(
        private ContentUploaderDashboardService $dashboardService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isContentUploader()) {
            return $next($request);
        }

        $dashboard = $this->dashboardService->forUser($user);
        $geminiPending = $dashboard['geminiPending'];
        $pendingCount = (int) ($dashboard['summary']['gemini_pending'] ?? 0);

        if ($pendingCount <= 0 || $geminiPending->isEmpty()) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if (! $routeName) {
            return $next($request);
        }

        $blocksNewUpload = in_array($routeName, self::BLOCKED_WHILE_PENDING, true)
            || Str::startsWith($routeName, 'content.textbooks.');

        if (! $blocksNewUpload) {
            return $next($request);
        }

        $this->emailGeminiPendingOncePerDay($user, $geminiPending);

        $message = $pendingCount === 1
            ? 'Gemini check is pending on 1 uploaded chapter. Complete that Gemini check before starting any new upload.'
            : "Gemini check is pending on {$pendingCount} uploaded chapters. Complete those Gemini checks before starting any new upload.";

        $redirect = $request->headers->get('referer')
            && $request->headers->get('referer') !== $request->fullUrl()
            ? redirect()->back()
            : redirect()->route('content.tasks.index');

        return $redirect->with('error', $message);
    }

    /**
     * Email the uploader once per day to avoid spam.
     */
    private function emailGeminiPendingOncePerDay($user, $geminiPending): void
    {
        if (! filled((string) $user->email) || ! str_contains((string) $user->email, '@')) {
            return;
        }

        $today = now()->toDateString();
        $cacheKey = "content-uploader-gemini-pending-email-sent:{$user->id}:{$today}";

        if (Cache::has($cacheKey)) {
            return;
        }

        ContentOperationsMailer::notifyGeminiPendingUploader($user, $geminiPending);
        Cache::put($cacheKey, true, now()->addDay());
    }
}
