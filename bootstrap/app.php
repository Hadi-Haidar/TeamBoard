<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Add Sanctum middleware to API routes (handles sessions automatically)
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class, // ← Move CORS FIRST
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);
        
        // Remove the duplicate CORS middleware from here
        // $middleware->use([
        //     \Illuminate\Http\Middleware\HandleCors::class,
        // ]);
        
        // Alias middleware
        $middleware->alias([
            'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

// CORS fix applied - middleware order corrected
