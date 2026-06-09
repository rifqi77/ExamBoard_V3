<?php

namespace App\Support;

/**
 * Exam access-token helpers, port of the original tokens.ts.
 * Digest = sha256(upper(trim(token))) hex — deterministic, stored unique.
 * Plain code = 6 chars from a 32-char ambiguity-free alphabet.
 */
class Tokens
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I/L
    private const LENGTH = 6;

    public static function digest(string $token): string
    {
        return hash('sha256', strtoupper(trim($token)));
    }

    public static function generatePlain(): string
    {
        $bytes = random_bytes(self::LENGTH);
        $out = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $out .= self::ALPHABET[ord($bytes[$i]) % 32];
        }

        return $out;
    }
}
