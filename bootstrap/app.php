<?php

use App\Http\Middleware\EnsureRegistrationIsOpen;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetTeamUrlDefaults;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureRegistrationIsOpen::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetTeamUrlDefaults::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Expected statuses always get the human page: a 404 or a 403 is not a
        // bug and there is nothing to debug. Server faults keep Laravel's
        // debug screen while debugging is on, because hiding a stack trace
        // behind a friendly sentence helps nobody.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            // Anything that asked for JSON keeps getting JSON. Handing an
            // HTML page to an API client turns a readable 404 into noise.
            if ($request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $status = $response->getStatusCode();
            $expected = in_array($status, [401, 403, 404, 410, 419, 429], true);
            $fault = in_array($status, [500, 503], true) && ! config('app.debug');

            if (! $expected && ! $fault) {
                return $response;
            }

            return Inertia::render('Error', ['status' => $status])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
