<?php

namespace App\Ingest;

/**
 * What one ingest cycle changed.
 */
final readonly class IngestTick
{
    /**
     * @param  list<int>  $insertedIds
     * @param  list<int>  $deactivatedIds
     */
    public function __construct(
        public array $insertedIds,
        public array $deactivatedIds,
    ) {}
}
