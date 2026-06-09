<?php

namespace App\Support;

/**
 * Seeded deterministic shuffle, port of the original shuffle.ts.
 * Same seed => same order. Mulberry32-style LCG. Used for per-session
 * shuffling of questions/options and auto-fill bank picking.
 */
class Shuffle
{
    /** Returns a closure producing floats in [0,1) for the given seed. */
    public static function seededRandom(string $seed): \Closure
    {
        $hash = 0;
        $len = strlen($seed);
        for ($i = 0; $i < $len; $i++) {
            // hash = (hash << 5) - hash + charCode; hash |= 0  (32-bit int)
            $hash = self::toInt32(self::toInt32($hash << 5) - $hash + ord($seed[$i]));
        }
        $state = ($hash & 0xFFFFFFFF);
        if ($state === 0) {
            $state = 1;
        }

        return function () use (&$state): float {
            $state = ($state * 1664525 + 1013904223) & 0xFFFFFFFF;

            return $state / 0xFFFFFFFF;
        };
    }

    /** @template T  @param array<int,T> $array  @return array<int,T> */
    public static function withSeed(array $array, string $seed): array
    {
        $rng = self::seededRandom($seed);
        $result = array_values($array);
        for ($i = count($result) - 1; $i > 0; $i--) {
            $j = (int) floor($rng() * ($i + 1));
            [$result[$i], $result[$j]] = [$result[$j], $result[$i]];
        }

        return $result;
    }

    private static function toInt32(int|float $n): int
    {
        $n = (int) (fmod((float) $n, 4294967296.0));
        if ($n >= 2147483648) {
            $n -= 4294967296;
        } elseif ($n < -2147483648) {
            $n += 4294967296;
        }

        return $n;
    }
}
