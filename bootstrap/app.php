<?php

use App\Http\Middleware\RequirePermission;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // JSON API: session cookie + CSRF (the `web` group), versioned under /api/v1.
            Route::middleware('web')->prefix('api/v1')->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['permission' => RequirePermission::class]);
        $middleware->validateCsrfTokens(except: ['api/v1/webhooks/*']);
        // Behind a proxy / cPanel, trust forwarded headers so rate limits and audit logs see real client IPs.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = fn (Request $r) => $r->is('api/*') || $r->expectsJson();
        $exceptions->shouldRenderJsonWhen($wantsJson);

        // Same error shape as the original FastAPI backend: {"detail": "…"} or a list of field errors,
        // so the React client's error handling works unchanged.
        $exceptions->render(function (ValidationException $e, Request $r) use ($wantsJson) {
            if (! $wantsJson($r)) {
                return null;
            }
            $detail = [];
            foreach ($e->errors() as $field => $messages) {
                $detail[] = ['loc' => ['body', ...explode('.', $field)], 'msg' => $messages[0], 'type' => 'value_error'];
            }

            return response()->json(['detail' => $detail], 422);
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($wantsJson) {
            return $wantsJson($r) ? response()->json(['detail' => 'Not authenticated'], 401) : null;
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($wantsJson) {
            if (! $wantsJson($r)) {
                return null;
            }
            $status = $e->getStatusCode();
            $message = $e->getMessage() ?: (Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error');

            return response()->json(['detail' => $status === 404 && ! $e->getMessage() ? 'Not found' : $message], $status, $e->getHeaders());
        });
    })->create();
