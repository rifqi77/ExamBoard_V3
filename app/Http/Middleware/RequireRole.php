<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Not signed in.'], 401);
            }

            return redirect()->guest('/');
        }
        if (! $user->active) {
            return response()->json(['error' => 'Account is deactivated.'], 403);
        }
        if (! in_array($user->role, $roles, true)) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['error' => 'Forbidden.'], 403);
            }
            abort(403, 'Forbidden.');
        }

        return $next($request);
    }
}
