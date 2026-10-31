<?php

namespace App\Billing;

/**
 * One call to bill, as the meter needs it.
 */
final readonly class BillableCall
{
    public function __construct(
        public int $userId,
        public int $tokenId,
        public string $endpoint,
        public string $client,
        public ?string $idempotencyKey,
        public string $fingerprint,
    ) {}
}
