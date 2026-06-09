<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserCredential;
use App\Support\JwtCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Cookie;

class AuthController extends Controller
{
    private const FAILED_ATTEMPTS_THRESHOLD = 5;
    private const LOCKOUT_MINUTES = 15;

    public function showLogin(Request $request)
    {
        if ($request->user()) {
            return redirect(self::homeFor($request->user()->role));
        }

        return Inertia::render('Login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => 'required|string|max:120',
            'password' => 'required|string|max:256',
        ]);

        $username = trim($data['username']);
        $ip = $request->ip() ?: 'local';
        $ipKey = 'login:ip:' . $ip;
        $userKey = 'login:user:' . mb_strtolower($username);

        if (RateLimiter::tooManyAttempts($ipKey, (int) config('exam.login_ip_rate_limit', 1000))
            || RateLimiter::tooManyAttempts($userKey, 10)) {
            throw ValidationException::withMessages([
                'username' => 'Too many requests. Try again later.',
            ]);
        }
        RateLimiter::hit($ipKey, 60);
        RateLimiter::hit($userKey, 60);

        $user = User::where('username', $username)->first();
        $cred = $user ? UserCredential::find($user->id) : null;

        if (! $user || ! $cred) {
            throw ValidationException::withMessages([
                'username' => 'Invalid username or password.',
            ]);
        }

        if ($cred->locked_until && $cred->locked_until->isFuture()) {
            $mins = (int) ceil(now()->diffInSeconds($cred->locked_until) / 60);
            throw ValidationException::withMessages([
                'username' => "Too many failed attempts. Try again in {$mins} minute(s).",
            ]);
        }

        if (! Hash::check($data['password'], $cred->password_hash)) {
            $next = $cred->failed_attempts + 1;
            $shouldLock = $next >= self::FAILED_ATTEMPTS_THRESHOLD;
            $cred->failed_attempts = $next;
            if ($shouldLock) {
                $cred->locked_until = now()->addMinutes(self::LOCKOUT_MINUTES);
            }
            $cred->save();

            throw ValidationException::withMessages([
                'username' => $shouldLock
                    ? 'Too many failed attempts. Your account is locked for 15 minutes.'
                    : 'Invalid username or password.',
            ]);
        }

        if (! $user->active) {
            throw ValidationException::withMessages([
                'username' => 'This account has been deactivated. Contact your administrator.',
            ]);
        }

        $cred->failed_attempts = 0;
        $cred->locked_until = null;
        $cred->last_sign_in_at = now();
        $cred->save();

        $jwt = JwtCookies::signSession($user->id, $user->role, (int) $user->token_version);

        return redirect(self::homeFor($user->role))->withCookie($this->sessionCookie($jwt));
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        if ($user) {
            // Bump tokenVersion -> invalidates every outstanding JWT for this user.
            User::where('id', $user->id)->update(['token_version' => $user->token_version + 1]);
        }

        return redirect('/')->withCookie($this->forgetSessionCookie());
    }

    public static function homeFor(string $role): string
    {
        return match ($role) {
            'admin' => '/admin',
            'teacher' => '/teacher',
            default => '/student',
        };
    }

    private function sessionCookie(string $jwt): Cookie
    {
        return cookie(
            JwtCookies::sessionCookieName(),
            $jwt,
            (int) (JwtCookies::sessionTtlSeconds() / 60),
            '/',
            null,
            app()->isProduction(), // secure
            true,                  // httpOnly
            false,                 // raw
            'lax'
        );
    }

    private function forgetSessionCookie(): Cookie
    {
        return cookie(
            JwtCookies::sessionCookieName(),
            '',
            -1,
            '/',
            null,
            app()->isProduction(),
            true,
            false,
            'lax'
        );
    }
}
