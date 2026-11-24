<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectUsersTo('/projects');
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*', 'workshop/agents*', 'projects/*/agents', 'projects/folders') || $request->expectsJson(),
        );
        $exceptions->report(function (Throwable $exception): ?bool {
            if (request()->is('workshop/agents*', 'projects/*/agents', 'projects/folders')) {
                Log::error('Workshop request failed.', ['exception_type' => $exception::class]);

                return false;
            }

            return null;
        });
        $exceptions->render(function (Throwable $exception, Request $request): ?JsonResponse {
            if (! $request->is('workshop/agents*', 'projects/*/agents', 'projects/folders')) {
                return null;
            }

            if ($exception instanceof ValidationException) {
                return response()->json(['message' => 'Please check the submitted values.', 'errors' => $exception->errors()], 422);
            }

            if ($exception instanceof AuthenticationException) {
                return response()->json(['message' => 'Please sign in again.'], 401);
            }

            if ($exception instanceof ModelNotFoundException || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() === 404)) {
                return response()->json(['message' => 'Agent not found.'], 404);
            }

            if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
                return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode(), $exception->getHeaders());
            }

            return response()->json(['message' => 'The workshop is temporarily unavailable. Check the server and reconnect.'], 503);
        });
    })->create();
