<?php

namespace App\Support\Errors;

use Illuminate\Http\Request;

/**
 * Caller input made safe to put in an error message: valid UTF-8 (so the JSON always encodes),
 * short (so a huge input can't make a huge response), and with anything that looks like an
 * API key replaced (so a key pasted into the wrong place is never echoed back).
 */
final class SafeText
{
    public const MAX_LENGTH = 40;

    /** A key, in any case, also with its underscore still percent-encoded. */
    private const KEY = '/ghk(?:_|%5f)[A-Za-z0-9]*/i';

    public static function of(string $input, int $max = self::MAX_LENGTH): string
    {
        $text = (string) preg_replace(self::KEY, 'ghk_[redacted]', mb_scrub($input, 'UTF-8'));

        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }

    public static function containsKey(string $input): bool
    {
        return preg_match(self::KEY, $input) === 1;
    }

    /**
     * The request path, decoded, for "There is no GET /…" style messages.
     */
    public static function path(Request $request): string
    {
        return self::of('/'.ltrim($request->decodedPath(), '/'), 100);
    }
}
