<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureProjectIsShown;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First, so every later middleware and log line of the request carries the ID.
        $middleware->prepend(AssignRequestId::class);
        // Before route-model binding (SB-7): Livewire binds `{project:name}` inside SubstituteBindings,
        // and an unknown name would 404 there before the guard could log why.
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureProjectIsShown::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
