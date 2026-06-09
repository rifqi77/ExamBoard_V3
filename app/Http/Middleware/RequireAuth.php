<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny($request, 401, 'Not signed in.');
        }
        if (! $user->active) {
            return $this->deny($request, 403, 'Account is deactivated.');
        }

        return $next($request);
    }

    private function deny(Request $request, int $status, string $message): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['error' => $message], $status);
        }
        // Browser navigation to a protected page while logged out -> login.
        return redirect()->guest('/');
    }
}
