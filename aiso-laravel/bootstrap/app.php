<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\CheckSuspension;
use App\Http\Middleware\EnforceLicense;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\EnsureOwner;
use App\Http\Middleware\EnsureVendor;
use App\Services\LanguageService;
use App\Services\VendorAuth;
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
        // The vendor's device cookie is typed into the browser by hand, so it cannot be an encrypted Laravel cookie.
        $middleware->encryptCookies(except: [VendorAuth::COOKIE]);
        $middleware->alias([
            'can' => EnsureCapability::class,
            'owner' => EnsureOwner::class,
            'suspension' => CheckSuspension::class,
            'license' => EnforceLicense::class,
            'vendor' => EnsureVendor::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every /api error is answered as JSON {"message": "..."}, in the language of the request (as the Spring backend did).
        $json = static function (Request $r, string $message, int $status, array $extra = []): \Illuminate\Http\JsonResponse {
            return response()->json(['message' => app(LanguageService::class)->forRequest($message)] + $extra, $status);
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

            // The reference ties this answer to its entry in storage/logs, so the cause can be found. The owner also gets the
            // cause itself (class and message, without server paths); other roles only get the reference.
            $reference = 'E-' . strtoupper(\Illuminate\Support\Str::random(6));
            \Illuminate\Support\Facades\Log::error("[{$reference}] " . $e::class . ': ' . $e->getMessage(), ['exception' => $e]);
            $extra = ['reference' => $reference];
            if ($r->user()?->isOwner()) {
                $extra['detail'] = class_basename($e) . ': ' . mb_substr(str_replace(base_path(), '', $e->getMessage()), 0, 300);
            }

            return $json($r, 'Unexpected server error', 500, $extra);
        });
    })
    ->create();
