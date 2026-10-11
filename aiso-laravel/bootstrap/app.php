<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\CheckSuspension;
use App\Http\Middleware\EnsureRole;
use App\Services\LanguageService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => EnsureRole::class,
            'suspension' => CheckSuspension::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every /api error is answered as JSON {"message": "..."}, in the language of the request (as the Spring backend did).
        $json = static function (Request $r, string $message, int $status): \Illuminate\Http\JsonResponse {
            return response()->json(['message' => app(LanguageService::class)->forRequest($message)], $status);
        };

        $exceptions->render(function (ApiException $e, Request $r) use ($json) {
            return $json($r, $e->getMessage(), $e->status);
        });

        $exceptions->render(function (ValidationException $e, Request $r) use ($json) {
            if (!$r->is('api/*')) {
                return null;
            }
            $message = collect($e->errors())
                ->map(fn (array $messages, string $field) => $field . ': ' . $messages[0])
                ->implode('; ');

            return $json($r, $message, 400);
        });

        $exceptions->render(function (AuthenticationException $e, Request $r) use ($json) {
            if (!$r->is('api/*')) {
                return null;
            }

            return $json($r, 'Unauthenticated.', 401);
        });

        $exceptions->render(function (\Throwable $e, Request $r) use ($json) {
            if (!$r->is('api/*')) {
                return null;
            }
            if ($e instanceof HttpExceptionInterface) {
                return $json($r, $e->getMessage() ?: 'Request failed', $e->getStatusCode());
            }

            return $json($r, 'Unexpected server error', 500);
        });
    })
    ->create();
