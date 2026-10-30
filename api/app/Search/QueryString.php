<?php

namespace App\Search;

/**
 * Splits a raw query string into name/value pairs, keeping repeats and names exactly as sent.
 */
final class QueryString
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function pairs(string $queryString): array
    {
        $pairs = [];
        foreach (explode('&', $queryString) as $part) {
            if ($part === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $pairs[] = [urldecode($name), urldecode($value)];
        }

        return $pairs;
    }
}
