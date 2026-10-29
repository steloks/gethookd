<?php

use App\Ingest\Cadence;

it('sleeps for the rest of the interval', function (int $intervalMs, int $elapsedNs, int $expectedUs) {
    expect(Cadence::sleepMicroseconds($intervalMs, $elapsedNs))->toBe($expectedUs);
})->with([
    'quick cycle' => [1000, 150_000_000, 850_000],
    'cycle used the whole interval' => [1000, 1_000_000_000, 0],
    'cycle overran: start the next one at once' => [1000, 1_400_000_000, 0],
    'no interval' => [0, 5_000_000, 0],
]);
