<?php

namespace App\Http\Middleware;

use App\Models\TextbookChapter;
use App\Services\ContentTextbookAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTextbookChapterAccess
{
    public function __construct(private ContentTextbookAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $chapter = $request->route('textbookChapter');

        if ($chapter instanceof TextbookChapter) {
            $this->access->authorizeChapter($user, $chapter);
        }

        return $next($request);
    }
}
