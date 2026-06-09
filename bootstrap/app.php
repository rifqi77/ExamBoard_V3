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
            \App\Http\Middleware\ResolveSession::class,
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\VerifyOrigin::class,
        ]);

        $middleware->alias([
            'auth.session' => \App\Http\Middleware\RequireAuth::class,
            'role' => \App\Http\Middleware\RequireRole::class,
            'cap' => \App\Http\Middleware\RequireCapability::class,
        ]);

        // Our auth uses raw HS256 JWT cookies (parity with the original
        // Next.js app). They must NOT be wrapped by Laravel's cookie
        // encryption or the middleware would mangle the token.
        $middleware->encryptCookies(except: [
            'secure-exam-session',
            'secure-exam-access',
        ]);

        // CSRF is Origin/Referer-based (VerifyOrigin), matching the original —
        // not Laravel's token model.
        $middleware->validateCsrfTokens(except: ['*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
