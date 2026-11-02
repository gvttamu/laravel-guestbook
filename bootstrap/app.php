<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Signed-in users who open the sign-in page go to their inbox instead.
        $middleware->redirectUsersTo(fn (): string => route('inbox'));

        $middleware->alias([
            'abilities' => CheckAbilities::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A form submitted from a page that fails for a reason other than its fields goes back
        // to that page with a message, instead of replacing it with an error page. Server
        // errors keep Laravel's error page while debugging, so the stack trace is still shown.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $messages = [
                403 => 'You don’t have permission to do that.',
                404 => 'What you were working on no longer exists. Refresh the page to see the latest.',
                419 => 'Your session expired. Please try again.',
                429 => 'Too many attempts. Please wait a minute and try again.',
                500 => 'Something went wrong on our side. Please try again.',
                503 => 'The app is briefly unavailable. Please try again in a moment.',
            ];
            $status = $response->getStatusCode();

            if (! $request->inertia() || $request->isMethod('GET') || ! isset($messages[$status]) || ($status === 500 && config('app.debug'))) {
                return $response;
            }

            Inertia::flash('error', $messages[$status]);

            return back();
        });
    })->create();
