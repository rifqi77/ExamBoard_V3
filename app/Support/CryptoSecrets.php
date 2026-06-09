<?php

namespace App\Support;

use RuntimeException;

/**
 * Bespoke AES-256-GCM encryption-at-rest, port of the original
 * crypto-secrets.ts. Key = SHA-256( "{domain}\0{SESSION_SECRET}" ), so
 * each domain tag yields an unrelated 32-byte key. Wire format is exactly
 * three base64 segments joined by ":" => base64(iv):base64(tag):base64(ct).
 *
 * NOTE: deliberately NOT Laravel's Crypt/encrypted cast — that envelope is
 * incompatible. This matches the original so data is portable.
 */
class CryptoSecrets
{
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;
    private const CIPHER = 'aes-256-gcm';

    public const DOMAIN_AI_KEYS = 'ai-keys-v1';
    public const DOMAIN_STUDENT_PASSWORD = 'student-password-v1';
    public const DOMAIN_TOKEN_PREVIEW = 'token-preview-v1';

    private static function secret(): string
    {
        $s = (string) config('exam.session_secret', '');
        if (strlen($s) < 16) {
            throw new RuntimeException('SESSION_SECRET must be set and at least 16 characters.');
        }

        return $s;
    }

    private static function keyFor(string $domain): string
    {
        return hash('sha256', $domain . "\0" . self::secret(), true); // 32 raw bytes
    }

    public static function encrypt(string $domain, string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ct = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            self::keyFor($domain),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_BYTES
        );
        if ($ct === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($ct);
    }

    public static function decrypt(string $domain, ?string $payload): ?string
    {
        if ($payload === null) {
            return null;
        }
        if ($payload === '') {
            return '';
        }
        $parts = explode(':', $payload);
        if (count($parts) !== 3) {
            return null;
        }
        $iv = base64_decode($parts[0], true);
        $tag = base64_decode($parts[1], true);
        $ct = base64_decode($parts[2], true);
        if ($iv === false || $tag === false || $ct === false) {
            return null;
        }
        if (strlen($iv) !== self::IV_BYTES || strlen($tag) !== self::TAG_BYTES) {
            return null;
        }
        $pt = openssl_decrypt($ct, self::CIPHER, self::keyFor($domain), OPENSSL_RAW_DATA, $iv, $tag);

        return $pt === false ? null : $pt;
    }

    /** Heuristic: exactly 3 non-empty base64 segments joined by ":". */
    public static function looksEncrypted(string $value): bool
    {
        $parts = explode(':', $value);
        if (count($parts) !== 3) {
            return false;
        }
        foreach ($parts as $p) {
            if ($p === '' || ! preg_match('/^[A-Za-z0-9+\/]+=*$/', $p)) {
                return false;
            }
        }

        return true;
    }

    // ---- Domain-specific helpers (parity with original exports) ----

    public static function encryptSecret(string $plaintext): string
    {
        return self::encrypt(self::DOMAIN_AI_KEYS, $plaintext);
    }

    public static function decryptSecret(?string $payload): ?string
    {
        return self::decrypt(self::DOMAIN_AI_KEYS, $payload);
    }

    public static function encryptStudentPassword(string $plaintext): string
    {
        return self::encrypt(self::DOMAIN_STUDENT_PASSWORD, $plaintext);
    }

    public static function decryptStudentPassword(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }
        if (! self::looksEncrypted($payload)) {
            return $payload; // legacy plaintext passthrough
        }

        return self::decrypt(self::DOMAIN_STUDENT_PASSWORD, $payload) ?? $payload;
    }

    public static function encryptTokenPreview(string $plaintext): string
    {
        return self::encrypt(self::DOMAIN_TOKEN_PREVIEW, $plaintext);
    }

    public static function decryptTokenPreview(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return $payload;
        }
        if (! self::looksEncrypted($payload)) {
            return $payload; // legacy plaintext passthrough
        }

        return self::decrypt(self::DOMAIN_TOKEN_PREVIEW, $payload) ?? $payload;
    }
}
