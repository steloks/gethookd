<?php

namespace App\Search;

/**
 * "Did you mean" for parameter names and values.
 */
final class Suggest
{
    /**
     * The candidate the input most likely meant: a known alias, a case-insensitive match, or the
     * closest candidate by edit distance when the distance is small relative to its length
     * (so "x" never suggests "q").
     *
     * @param  list<string>  $candidates
     * @param  array<string, string>  $aliases  lowercase input => candidate
     */
    public static function closest(string $input, array $candidates, array $aliases = []): ?string
    {
        $lower = mb_strtolower(trim(mb_substr($input, 0, 64)));

        if (isset($aliases[$lower])) {
            return $aliases[$lower];
        }

        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates as $candidate) {
            $distance = levenshtein($lower, mb_strtolower($candidate));
            if ($distance < $bestDistance) {
                [$best, $bestDistance] = [$candidate, $distance];
            }
        }

        return $best !== null && $bestDistance <= 2 && $bestDistance < strlen($best) / 2 ? $best : null;
    }
}
