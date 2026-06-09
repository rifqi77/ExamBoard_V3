<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\JwtCookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class ImpersonationController extends Controller
{
    /** Admin -> view as a teacher. Mints a session JWT with imp_uid = admin. */
    public function start(Request $request, string $uid)
    {
        $admin = $request->user();
        $target = User::find($uid);
        if (! $target) {
            abort(404, 'Teacher not found.');
        }
        if ($target->role !== 'teacher') {
            abort(400, 'Only teacher accounts can be impersonated.');
        }
        if (! $target->active) {
            abort(400, 'Cannot impersonate a deactivated teacher.');
        }

        $jwt = JwtCookies::signSession($target->id, 'teacher', (int) $target->token_version, $admin->id);

        return redirect('/teacher')->withCookie($this->cookie($jwt));
    }

    /** Return to the original admin session. */
    public function stop(Request $request)
    {
        $user = $request->user();
        $sourceUid = $user?->getAttribute('impersonation_source_uid');
        if (! $user || ! $sourceUid) {
            abort(400, 'No active impersonation session.');
        }
        $admin = User::find($sourceUid);
        if (! $admin) {
            abort(404, 'Original admin not found.');
        }
        if ($admin->role !== 'admin' || ! $admin->active) {
            abort(403, 'Original admin unavailable.');
        }

        $jwt = JwtCookies::signSession($admin->id, 'admin');

        return redirect('/admin')->withCookie($this->cookie($jwt));
    }

    private function cookie(string $jwt): Cookie
    {
        return cookie(
            JwtCookies::sessionCookieName(),
            $jwt,
            (int) (JwtCookies::sessionTtlSeconds() / 60),
            '/',
            null,
            app()->isProduction(),
            true,
            false,
            'lax'
        );
    }
}
