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

    public static function of(string $input, int $max = self::MAX_LENGTH): string
    {
        $text = (string) preg_replace('/ghk_[A-Za-z0-9]*/', 'ghk_[redacted]', mb_scrub($input, 'UTF-8'));

        return mb_strimwidth($text, 0, $max, '…', 'UTF-8');
    }

    /**
     * The request path, for "There is no GET /…" style messages.
     */
    public static function path(Request $request): string
    {
        return self::of('/'.ltrim($request->path(), '/'), 100);
    }
}
