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
        // Cookie zona waktu ditulis JS (tidak terenkripsi); nilainya divalidasi di ResolveUserTimezone.
        $middleware->encryptCookies(except: ['tk_tz']);
        $middleware->web(append: [\App\Http\Middleware\ResolveUserTimezone::class]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'tutor.verified' => \App\Http\Middleware\EnsureVerifiedTutor::class,
            'student.verified' => \App\Http\Middleware\EnsureVerifiedStudent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
