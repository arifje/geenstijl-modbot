<?php

use App\Http\Middleware\NoIndex;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust X-Forwarded-* (client IP for login throttling, https) only from
        // the proxy in front of the app: by default loopback and the private
        // ranges Docker and Kubernetes use. A client connecting directly from
        // elsewhere cannot spoof its IP. Set TRUSTED_PROXIES to narrow or widen.
        $middleware->trustProxies(at: array_map('trim', explode(',', (string) env(
            'TRUSTED_PROXIES',
            '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12',
        ))));

        // Private admin: keep it out of search engines.
        $middleware->append(NoIndex::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
