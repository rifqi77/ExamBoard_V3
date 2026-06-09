<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection via Origin/Referer host matching, port of the original
 * middleware.ts. Every state-changing request must carry an Origin or
 * Referer whose host matches the request host.
 */
class VerifyOrigin
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        $host = $request->getHttpHost();
        if (! $host) {
            return response()->json(['error' => 'Bad request: missing host.'], 400);
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null && $origin !== '') {
            return $this->hostOf($origin) === $host
                ? $next($request)
                : response()->json(['error' => 'Cross-origin request rejected.'], 403);
        }

        $referer = $request->headers->get('Referer');
        if ($referer !== null && $referer !== '') {
            return $this->hostOf($referer) === $host
                ? $next($request)
                : response()->json(['error' => 'Cross-origin request rejected.'], 403);
        }

        return response()->json([
            'error' => 'Missing Origin/Referer header — cross-origin request rejected.',
        ], 403);
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return null;
        }
        $port = parse_url($url, PHP_URL_PORT);

        return $port ? "{$host}:{$port}" : $host;
    }
}
