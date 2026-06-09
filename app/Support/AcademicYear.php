<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Academic year helpers (July->June), port of the original academic-year.ts.
 * "2025/2026" = starts July 2025, ends June 2026.
 */
class AcademicYear
{
    public static function current(?DateTimeInterface $now = null): string
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $y = (int) $now->format('Y');
        $m = (int) $now->format('n'); // 1-based

        return $m >= 7 ? ($y . '/' . ($y + 1)) : (($y - 1) . '/' . $y);
    }

    /** Accepts "YYYY/YYYY" where the second year is exactly first+1. */
    public static function parse(string $input): ?string
    {
        if (! preg_match('/^(\d{4})\s*\/\s*(\d{4})$/', trim($input), $m)) {
            return null;
        }
        $a = (int) $m[1];
        $b = (int) $m[2];
        if ($b !== $a + 1) {
            return null;
        }

        return $a . '/' . $b;
    }
}
