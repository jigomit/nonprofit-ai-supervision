<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses the sign-up form where the host has not opened it.
 *
 * Done here rather than by dropping the Fortify feature so that the route
 * table is identical in every environment: Wayfinder generates the frontend
 * from live routes, and a route that exists only in development produces a
 * bundle that cannot be built in production.
 *
 * Fortify declares its own routes, so this sits on the web group and picks
 * out the two that matter by name.
 */
class EnsureRegistrationIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('register', 'register.store') && ! config('signoff.registration')) {
            abort(403, 'This application does not take sign-ups. Organizations are invited to a team.');
        }

        return $next($request);
    }
}
