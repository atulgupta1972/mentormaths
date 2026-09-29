<?php

namespace App\Http\Middleware;

use App\Models\ContentUploadTask;
use App\Models\TextbookChapter;
use App\Services\ContentUploaderDashboardService;
use App\Support\ContentOperationsMailer;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureUploaderGeminiCheckComplete
{
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

        // Always allow the uploader to open the task list, and allow working on the
        // specific tasks that still need Gemini.
        if ($routeName === 'content.tasks.index') {
            return $next($request);
        }

        // Allow all task actions (viewing + verifying + Gemini paste verification).
        // The restriction is only about starting new chapter upload/import/publishing.
        if (Str::startsWith($routeName, 'content.tasks.')) {
            return $next($request);
        }

        // Concept builder is a separate job — do not trap the uploader on the task list
        // after they agree, even if another chapter still needs Gemini.
        if ($this->isConceptBuilderWork($request, $user)) {
            return $next($request);
        }

        // Block chapter actions (upload/import) while Gemini is pending.
        if (Str::startsWith($routeName, 'content.chapters.')
            || Str::startsWith($routeName, 'content.textbooks.')
        ) {
            $this->emailGeminiPendingOncePerDay($user, $geminiPending);

            return redirect()
                ->route('content.tasks.index')
                ->with('error', 'Gemini check is pending. Complete Gemini for the highlighted tasks before starting any new upload.');
        }

        return $next($request);
    }

    private function isConceptBuilderWork(Request $request, $user): bool
    {
        $routeName = (string) $request->route()?->getName();

        if (Str::startsWith($routeName, 'content.concept-builder.')
            || Str::startsWith($routeName, 'content.textbooks.concept-path')
        ) {
            return true;
        }

        $chapterRoutes = [
            'content.textbooks.show',
            'content.textbooks.upload-pdf',
            'content.textbooks.download',
        ];

        if (! in_array($routeName, $chapterRoutes, true)) {
            return false;
        }

        $chapter = $request->route('textbookChapter');
        if (! $chapter instanceof TextbookChapter) {
            return false;
        }

        return ContentUploadTask::query()
            ->where('textbook_chapter_id', $chapter->id)
            ->where('assigned_to_user_id', $user->id)
            ->where('work_type', ContentUploadTask::WORK_TYPE_CONCEPT_PATH_BUILD)
            ->whereNotIn('status', [
                ContentUploadTask::STATUS_CANCELLED,
                ContentUploadTask::STATUS_SUBMITTED_FOR_PUBLISH,
                ContentUploadTask::STATUS_PUBLISHED,
            ])
            ->exists();
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

