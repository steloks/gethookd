<?php

namespace App\Ingest;

/**
 * Keeps ingest cycles starting a fixed interval apart, measured start to start, so time spent
 * working does not add drift. A cycle that overruns is followed immediately; missed cycles are
 * not made up in a burst.
 */
final class Cadence
{
    public static function sleepMicroseconds(int $intervalMs, int $elapsedNs): int
    {
        return max(0, $intervalMs * 1000 - intdiv($elapsedNs, 1000));
    }
}
