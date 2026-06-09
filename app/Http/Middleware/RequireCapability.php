<?php

namespace App\Http\Middleware;

use App\Support\Capabilities;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Capability gate, port of requireCap(). Admins bypass entirely. Non-teachers
 * are rejected. Teachers must have the capability enabled.
 */
class RequireCapability
{
    public function handle(Request $request, Closure $next, string $cap): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'Not signed in.'], 401);
        }
        if ($user->role === 'admin') {
            return $next($request); // admin bypasses
        }
        if ($user->role !== 'teacher') {
            return response()->json(['error' => 'This action requires a teacher account.'], 403);
        }
        if (! Capabilities::has($user->capabilities, $cap)) {
            return response()->json([
                'error' => "This feature (\"{$cap}\") is disabled for your account. Ask your administrator to enable it.",
            ], 403);
        }

        return $next($request);
    }
}
