<?php

namespace App\Support;

/**
 * Shared student username / password generators, port of the original
 * student-credentials.ts. Used by roster import and bulk password reset
 * so both produce identical, predictable credentials.
 */
class StudentCredentials
{
    /** Indonesian honorific prefixes skipped when picking a nickname. */
    private const TITLE_SKIP = [
        'hj', 'h', 'dr', 'drs', 'dra', 'ir', 'prof', 'kh',
        'haji', 'hajj', 'mr', 'mrs', 'ms',
    ];

    public static function extractNickname(string $fullName): string
    {
        $words = preg_split('/\s+/', trim($fullName)) ?: [];
        foreach ($words as $w) {
            $clean = self::stripAccents(mb_strtolower($w));
            $clean = preg_replace('/[^a-z0-9]/', '', $clean) ?? '';
            if (strlen($clean) >= 2 && ! in_array($clean, self::TITLE_SKIP, true)) {
                return $clean;
            }
        }

        return 'siswa';
    }

    public static function generateUsernameFromName(string $fullName, array $taken): string
    {
        $nickname = substr(self::extractNickname($fullName), 0, 24);
        $base = strlen($nickname) >= 2 ? $nickname : 'siswa';
        $takenLower = array_map('strtolower', $taken);
        for ($attempt = 0; $attempt < 99; $attempt++) {
            $candidate = $base . self::randomThreeDigits();
            if (! in_array(strtolower($candidate), $takenLower, true)) {
                return $candidate;
            }
        }

        return $base . self::randomThreeDigits() . substr(bin2hex(random_bytes(2)), 0, 4);
    }

    public static function generatePasswordFromName(string $fullName): string
    {
        $nickname = substr(self::extractNickname($fullName), 0, 30);
        $base = strlen($nickname) >= 2 ? $nickname : 'siswa';

        return $base . date('Y');
    }

    private static function randomThreeDigits(): string
    {
        return str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);
    }

    private static function stripAccents(string $s): string
    {
        if (class_exists(\Normalizer::class)) {
            $decomposed = \Normalizer::normalize($s, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                return preg_replace('/\p{Mn}/u', '', $decomposed) ?? $decomposed;
            }
        }
        // Fallback when ext-intl is unavailable: best-effort ASCII transliterate.
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);

        return $t !== false ? $t : $s;
    }
}
