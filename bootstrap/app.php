<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',   // versioned mobile/web API: /api/v1/...
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'force.password'     => \App\Http\Middleware\ForcePasswordChange::class,
            'platform.admin'     => \App\Http\Middleware\EnsurePlatformAdmin::class,
            'api.active'         => \App\Http\Middleware\EnsureApiUserActive::class,
            'api.tenant'         => \App\Http\Middleware\ResolveApiTenant::class,
            'idempotent'         => \App\Http\Middleware\Idempotent::class,
            'plan'               => \App\Http\Middleware\EnforcePlan::class,
        ]);

        // Every request gets a correlation id (logs, audit trail, X-Request-Id header).
        $middleware->prepend(\App\Http\Middleware\AssignCorrelationId::class);
        // A house's own domain is served as its subdomain (house websites).
        $middleware->prepend(\App\Http\Middleware\ResolveSiteDomain::class);

        // Web: work inside the user's fashion house; temporary passwords must be changed first.
        $middleware->web(append: [
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\ForcePasswordChange::class,
            \App\Http\Middleware\EnforcePlan::class,   // plan features (subscriptions)
        ]);

        // Tenant context must exist BEFORE route-model binding, so a URL like
        // /users/{user} can never resolve a record from another tenant.
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\ResolveTenant::class,
        );
        $middleware->prependToPriorityList(
            before: \Illuminate\Routing\Middleware\SubstituteBindings::class,
            prepend: \App\Http\Middleware\ResolveApiTenant::class,
        );

        $middleware->validateCsrfTokens(except: [
            'webhook/*',
            'webhooks/*',
        ]);

        // Expired-session handling for CSRF failures (redirect to login instead of a bare 419).
        $middleware->replace(
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            \App\Http\Middleware\CustomVerifyCsrfToken::class
        );

        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The API always answers in JSON, even without an Accept header.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

        // API v1 error envelope (SRS §8.3): code, message, field_errors, correlation_id. Never a stack trace.
        $exceptions->render(function (\Throwable $e, $request) {
            if (! $request->is('api/v1/*')) {
                return null;
            }

            $correlation = $request->attributes->get('correlation_id');
            $json = fn (int $status, string $code, string $message, array $extra = []) => response()->json(
                ['success' => false, 'code' => $code, 'message' => $message] + $extra + ['correlation_id' => $correlation],
                $status
            );

            if ($e instanceof \Illuminate\Validation\ValidationException) {
                return $json($e->status, 'validation_failed',
                    collect($e->errors())->flatten()->first() ?: 'Please check the form.',
                    ['field_errors' => $e->errors()]);
            }
            if ($e instanceof \Illuminate\Auth\AuthenticationException) {
                return $json(401, 'unauthenticated', 'Please sign in again.');
            }
            if ($e instanceof \Illuminate\Auth\Access\AuthorizationException
                || $e instanceof \Spatie\Permission\Exceptions\UnauthorizedException) {
                return $json(403, 'forbidden', 'You are not allowed to do this.');
            }
            if ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                return $json(404, 'not_found', 'Not found.');
            }
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $code = [401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 405 => 'method_not_allowed', 429 => 'too_many_requests'][$status] ?? 'http_error';
                $msg = $e->getMessage() ?: ($status === 429 ? 'Too many requests. Please wait a moment.' : 'Request failed.');

                return $json($status, $code, $msg)->withHeaders($e->getHeaders());
            }
            if (config('app.debug')) {
                return null; // full trace in local development
            }

            report($e);

            return $json(500, 'server_error', 'Something went wrong. Please try again.');
        });

        // Expired session / CSRF token on web pages → back to login, keeping the intended URL.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }
            $intendedUrl = $request->fullUrl();

            if ($request->expectsJson()) {
                return response()->json([
                    'status'   => 'error',
                    'message'  => 'Session expired. Please login again.',
                    'redirect' => route('login', [], false),
                ], 419);
            }

            if (Auth::check()) {
                Auth::logout();
            }

            $request->session()->flush();
            $request->session()->regenerate();

            return redirect()->route('login')
                ->with('session_expired', true)
                ->with('error', 'Your session has expired. Please login again.')
                ->with('intended', $intendedUrl);
        });
    })->create();
