<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\EnsureAccountIsActive::class,
            // SECURITY / PASSWORD POLICY — forces a password change when
            // an Administrator has flagged the account
            // (must_change_password) or the password has expired
            // (security.password_expiry_days). Redirects to
            // 'password.change' (see routes/web.php and
            // ChangePasswordController), which is itself exempted inside
            // the middleware so it never redirects against itself.
            \App\Http\Middleware\EnsurePasswordIsCurrent::class,
        ]);

        // PERFORMANCE — compress API payloads. Gzips JSON/text
        // responses (recommendations, previews, report exports, etc.)
        // above a minimum size for any client that accepts gzip. See
        // App\Http\Middleware\CompressApiPayload for what it
        // deliberately skips (Inertia page visits, already-compressed
        // responses, tiny payloads).
        $middleware->append(\App\Http\Middleware\CompressApiPayload::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Belt-and-suspenders alongside the Route::fallback() in web.php:
        // any 404 that still slips through (e.g. a route with a parameter
        // that doesn't resolve, like /subjects/999) gets redirected
        // instead of showing Laravel's raw "404 | NOT FOUND" page.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return redirect(auth()->check() ? route('dashboard') : route('login'))
                    ->with('error', 'That page could not be found.');
            }
        });

        // Admin-only pages (e.g. User Management) abort(403) for any
        // other role that reaches them directly by URL. Redirect to the
        // dashboard with a flash message instead of Laravel's raw
        // "403 | FORBIDDEN" page, consistent with the 404 handling above.
        //
        // Scoped to GET requests only: this is meant for a person typing
        // a restricted page's URL directly into the browser. Write
        // endpoints (PATCH/POST/DELETE) — like the scheduling save
        // routes — always return JsonResponse and must surface a real
        // 403 to the caller, even if the request didn't set an Accept
        // header (a plain fetch()/test client won't, but the frontend's
        // real AJAX layer does; either way a write request should never
        // be silently redirected).
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, \Illuminate\Http\Request $request) {
            if ($request->isMethod('GET') && ! $request->expectsJson()) {
                return redirect(auth()->check() ? route('dashboard') : route('login'))
                    ->with('error', 'You do not have permission to access that page.');
            }
        });
    })->create();