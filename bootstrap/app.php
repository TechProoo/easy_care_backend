<?php

use App\Http\Responses\ApiErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prependToGroup('api', \App\Http\Middleware\AuditSecurityEvents::class);
        $middleware->appendToGroup('api', 'throttle:api');
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('api', 'api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): ?bool {
            if (request()->is('api/v1/patients', 'api/v1/patients/*')) {
                logger()->error('A patient database operation failed.');

                return false;
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api', 'api/*') || $request->expectsJson(),
        );
        $exceptions->respond(new ApiErrorResponse);
    })->create();
