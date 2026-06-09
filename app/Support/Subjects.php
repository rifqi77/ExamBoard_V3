<?php

namespace App\Support;

/**
 * Canonical bilingual subject list ("ENGLISH / INDONESIA" ALL CAPS),
 * port of the original subjects.ts. Used at every write site so the DB
 * never accumulates drift.
 */
class Subjects
{
    public const OTHER = '__other__';

    /** @var string[] */
    public const DEFAULTS = [
        'PHYSICS / FISIKA',
        'CHEMISTRY / KIMIA',
        'BIOLOGY / BIOLOGI',
        'MATH / MATEMATIKA',
        'EARTH SCIENCE / KEBUMIAN',
        'INFORMATICS / INFORMATIKA',
        'ENGLISH / BAHASA INGGRIS',
        'INDONESIAN / BAHASA INDONESIA',
        'HISTORY / SEJARAH',
        'GEOGRAPHY / GEOGRAFI',
        'ECONOMICS / EKONOMI',
        'SOCIOLOGY / SOSIOLOGI',
        'CIVICS / PPKN',
        'RELIGION / AGAMA',
        'ART / SENI BUDAYA',
        'PE / PENJASKES',
    ];

    public static function canonical(?string $raw): string
    {
        if ($raw === null) {
            return '';
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        $upper = mb_strtoupper($trimmed);
        foreach (self::DEFAULTS as $canonical) {
            if ($canonical === $upper) {
                return $canonical;
            }
        }
        foreach (self::DEFAULTS as $canonical) {
            foreach (explode(' / ', $canonical) as $half) {
                if (trim($half) === $upper) {
                    return $canonical;
                }
            }
        }

        return $upper;
    }

    /**
     * @param  string[]  $existing
     * @return string[]
     */
    public static function mergeWithExisting(array $existing): array
    {
        $set = array_values(self::DEFAULTS);
        foreach ($existing as $s) {
            $trimmed = trim((string) $s);
            if ($trimmed !== '') {
                $set[] = $trimmed;
            }
        }
        $set = array_values(array_unique($set));
        usort($set, fn ($a, $b) => strcasecmp($a, $b));

        return $set;
    }
}
