<?php

use App\Helpers\ApiFormatter;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo('/');
        $middleware->redirectUsersTo('/home');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($wantsJson) {
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return ApiFormatter::createApi(401, 'Unauthenticated.', null, [
                    ['code' => 'unauthenticated', 'message' => $request->is('api/*')
                        ? 'A valid API token is required.'
                        : 'Please log in again.'],
                ]);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                $errors = collect($e->errors())
                    ->flatMap(fn (array $messages, string $field) => collect($messages)->map(fn ($message) => [
                        'field' => $field,
                        'code' => 'invalid',
                        'message' => $message,
                    ]))
                    ->values()
                    ->all();

                return ApiFormatter::createApi(422, 'The given data was invalid.', null, $errors);
            }
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                $status = $e->getStatusCode();
                $message = match ($status) {
                    403 => 'Forbidden.',
                    404 => 'Resource not found.',
                    405 => 'Method not allowed.',
                    419 => 'Session expired, reload the page.',
                    429 => 'Too many requests.',
                    default => $status >= 500 ? 'Server error.' : 'Request failed.',
                };

                return ApiFormatter::createApi($status, $message, null, [
                    ['code' => 'http_'.$status, 'message' => $message],
                ])->withHeaders($e->getHeaders());
            }
        });
    })->create();
