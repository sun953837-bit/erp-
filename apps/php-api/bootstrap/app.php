<?php

use App\Http\Middleware\ForceJsonResponse;
use App\Support\ApiResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

if (PHP_VERSION_ID >= 80500) {
    // Temporary guard for upstream/vendor deprecation noise on PHP 8.5.
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->appendToGroup('api', [
            ForceJsonResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $e, Request $request) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                $e->getMessage(),
                422,
                $e->errors()
            );
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            return ApiResponse::error('NOT_FOUND', 'resource not found', 404);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($e instanceof HttpExceptionInterface) {
                return ApiResponse::error(
                    'INTERNAL_ERROR',
                    $e->getMessage() ?: 'internal error',
                    $e->getStatusCode()
                );
            }

            return ApiResponse::error('INTERNAL_ERROR', 'internal server error', 500);
        });
    })
    ->create();
