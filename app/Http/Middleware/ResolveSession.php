<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\JwtCookies;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the session JWT cookie into the effective User on every request
 * (profile loaded fresh from DB, tokenVersion checked for revocation), then
 * exposes it via $request->user() + an "impersonator" attribute. Mirrors the
 * original getCurrentSession().
 */
class ResolveSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $claims = JwtCookies::verifySession($request->cookie(JwtCookies::sessionCookieName()));
        $user = null;
        $impersonator = null;

        if ($claims) {
            $candidate = User::find($claims['uid']);
            if ($candidate) {
                $jwtVersion = $claims['tokenVersion'] ?? 0;
                if ($jwtVersion === (int) $candidate->token_version) {
                    $user = $candidate;
                    $user->setAttribute('impersonation_source_uid', $claims['impersonationSourceUid']);
                    if ($claims['impersonationSourceUid']) {
                        $impersonator = User::find($claims['impersonationSourceUid']);
                    }
                }
            }
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('impersonator', $impersonator);

        return $next($request);
    }
}
