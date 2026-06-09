<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Exam-access grant helper, port of requireExamAccess + applyExamAccessCookie.
 * The narrow 8h cookie authorises one user against one specific exam.
 */
class ExamAccess
{
    /** Throws 403 unless the request carries a valid grant for this exam (admins bypass). */
    public static function require(Request $request, User $user, string $examId): void
    {
        if ($user->role === 'admin') {
            return;
        }
        $grant = JwtCookies::verifyExamAccess($request->cookie(JwtCookies::EXAM_ACCESS_COOKIE));
        if (! $grant || $grant['userId'] !== $user->id || $grant['examId'] !== $examId) {
            abort(403, 'A valid exam access token is required.');
        }
    }

    public static function has(Request $request, User $user, string $examId): bool
    {
        if ($user->role === 'admin') {
            return true;
        }
        $grant = JwtCookies::verifyExamAccess($request->cookie(JwtCookies::EXAM_ACCESS_COOKIE));

        return $grant && $grant['userId'] === $user->id && $grant['examId'] === $examId;
    }

    public static function cookie(string $jwt): Cookie
    {
        return cookie(
            JwtCookies::EXAM_ACCESS_COOKIE,
            $jwt,
            (int) (JwtCookies::EXAM_ACCESS_TTL_SECONDS / 60),
            '/',
            null,
            app()->isProduction(),
            true,
            false,
            'lax'
        );
    }
}
