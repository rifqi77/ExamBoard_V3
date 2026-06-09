<?php

namespace App\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;
use Throwable;

/**
 * HS256 JWT session + exam-access cookies, port of the original session.ts.
 * Two cookies: long-lived session (uid/role/tv/imp_uid) and short-lived
 * exam-access grant (userId/examId/tokenId/scope). Signed with SESSION_SECRET.
 */
class JwtCookies
{
    public const ALG = 'HS256';
    public const EXAM_ACCESS_COOKIE = 'secure-exam-access';
    public const EXAM_ACCESS_TTL_SECONDS = 8 * 60 * 60; // 8h

    public static function sessionCookieName(): string
    {
        return (string) config('exam.cookie_name', 'secure-exam-session');
    }

    public static function sessionTtlSeconds(): int
    {
        return ((int) config('exam.cookie_days', 5)) * 24 * 60 * 60;
    }

    private static function key(): string
    {
        $s = (string) config('exam.session_secret', '');
        if (strlen($s) < 32) {
            throw new RuntimeException('SESSION_SECRET must be set and at least 32 characters for JWT signing.');
        }

        return $s;
    }

    public static function signSession(
        string $uid,
        string $role,
        ?int $tokenVersion = null,
        ?string $impersonationSourceUid = null
    ): string {
        $now = time();
        $payload = [
            'uid' => $uid,
            'role' => $role,
            'iat' => $now,
            'exp' => $now + self::sessionTtlSeconds(),
        ];
        if ($impersonationSourceUid !== null) {
            $payload['imp_uid'] = $impersonationSourceUid;
        }
        if ($tokenVersion !== null) {
            $payload['tv'] = $tokenVersion;
        }

        return JWT::encode($payload, self::key(), self::ALG);
    }

    /** @return array{uid:string,role:string,impersonationSourceUid:?string,tokenVersion:?int}|null */
    public static function verifySession(?string $jwt): ?array
    {
        if (! $jwt) {
            return null;
        }
        try {
            $d = (array) JWT::decode($jwt, new Key(self::key(), self::ALG));
        } catch (Throwable) {
            return null;
        }
        if (! isset($d['uid']) || ! is_string($d['uid'])) {
            return null;
        }
        if (! isset($d['role']) || ! in_array($d['role'], ['student', 'teacher', 'admin'], true)) {
            return null;
        }

        return [
            'uid' => $d['uid'],
            'role' => $d['role'],
            'impersonationSourceUid' => isset($d['imp_uid']) && is_string($d['imp_uid']) ? $d['imp_uid'] : null,
            'tokenVersion' => isset($d['tv']) ? (int) $d['tv'] : null,
        ];
    }

    public static function signExamAccess(string $userId, string $examId, string $tokenId): string
    {
        $now = time();

        return JWT::encode([
            'userId' => $userId,
            'examId' => $examId,
            'tokenId' => $tokenId,
            'scope' => 'exam_access',
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + self::EXAM_ACCESS_TTL_SECONDS,
        ], self::key(), self::ALG);
    }

    /** @return array{userId:string,examId:string,tokenId:string}|null */
    public static function verifyExamAccess(?string $jwt): ?array
    {
        if (! $jwt) {
            return null;
        }
        try {
            $d = (array) JWT::decode($jwt, new Key(self::key(), self::ALG));
        } catch (Throwable) {
            return null;
        }
        if (($d['scope'] ?? null) !== 'exam_access') {
            return null;
        }
        if (! is_string($d['userId'] ?? null) || ! is_string($d['examId'] ?? null) || ! is_string($d['tokenId'] ?? null)) {
            return null;
        }

        return [
            'userId' => $d['userId'],
            'examId' => $d['examId'],
            'tokenId' => $d['tokenId'],
        ];
    }
}
