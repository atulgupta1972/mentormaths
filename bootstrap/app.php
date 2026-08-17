<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\RecordUserLastSeen::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'content.uploader' => \App\Http\Middleware\EnsureContentUploader::class,
            'content.chapter' => \App\Http\Middleware\EnsureTextbookChapterAccess::class,
            'formula.drill' => \App\Http\Middleware\EnsureFormulaDrillComplete::class,
            'basics.drill' => \App\Http\Middleware\EnsureBasicsDrillComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request) {
            if ($response->getStatusCode() < 500 || $request->path() !== 'dashboard') {
                return $response;
            }

            if ($request->attributes->get('dashboard_500_handled')) {
                return $response;
            }

            $request->attributes->set('dashboard_500_handled', true);

            $message = $exception->getMessage().' ('.$exception->getFile().':'.$exception->getLine().')';

            Log::error('Admin dashboard 500 captured.', [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ]);

            @file_put_contents(
                storage_path('logs/dashboard-last-error.txt'),
                now()->toDateTimeString()."\n".$message."\n".$exception->getTraceAsString()
            );

            $payload = [
                'activeYear' => null,
                'selectedGrade' => null,
                'stats' => [
                    'students_count' => 0,
                    'upcoming_exams_count' => 0,
                    'pending_sets_count' => 0,
                    'under_review_sets_count' => 0,
                    'completed_sets_count' => 0,
                    'help_requests_count' => 0,
                    'content_publish_queue_count' => 0,
                ],
                'students' => [],
                'helpRequests' => [],
                'contentPublishQueue' => [],
                'contentRecheckQueue' => [],
                'examTypeOptions' => [],
            ];

            return Inertia::render('Dashboard', [
                'isAdmin' => (bool) $request->user()?->isAdmin(),
                'mailSettings' => null,
                'gradeLevels' => [],
                'loadError' => $message,
                ...$payload,
            ])->toResponse($request)->setStatusCode(200);
        });
    })->create();
