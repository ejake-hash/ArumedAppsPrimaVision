<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->use([
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*'
        ]);

        $middleware->alias([
            'role'                => \App\Http\Middleware\RoleMiddleware::class,
            'permission'          => \App\Http\Middleware\PermissionMiddleware::class,
            // Antrol (Antrean Online BPJS, Sisi B): validasi header x-token Mobile JKN.
            'verify-antrol-token' => \App\Http\Middleware\VerifyAntrolToken::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
