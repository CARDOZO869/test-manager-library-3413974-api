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
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $error) {
            if ($error instanceof DomainException || $error instanceof InvalidArgumentException || $error instanceof Illuminate\Validation\ValidationException) return false;
            Illuminate\Support\Facades\Log::error('Operation failed', [
                'exception_type'=>get_class($error),
                'source'=>basename($error->getFile()),
                'line'=>$error->getLine(),
            ]);
            return false;
        });
        $exceptions->render(function (Throwable $error, Illuminate\Http\Request $request) {
            if (!$request->is('api/*')) return null;
            return App\Http\ApiErrorResponse::render($error);
        });
    })->create();
