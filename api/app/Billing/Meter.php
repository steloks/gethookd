<?php

namespace App\Billing;

use App\Support\Errors\ApiError;
use App\Support\Errors\ErrorCode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * The billing meter: the billable_calls table is the only count of billable calls. The rule it
 * applies is written in REFERENCE.md ("Billing").
 */
final class Meter
{
    public const MONTHLY_QUOTA = 1000;

    public const IDEMPOTENCY_HOURS = 24;

    /**
     * Billable calls of the account in the calendar month (UTC) of $now.
     */
    public function usage(int $userId, CarbonInterface $now): int
    {
        $period = self::period($now);

        return DB::table('billable_calls')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $period[0])
            ->where('created_at', '<', $period[1])
            ->count();
    }

    /**
     * The fingerprint the Idempotency-Key was billed with, if it is still within its 24 hours.
     */
    public function billedFingerprint(int $tokenId, string $key, CarbonInterface $now): ?string
    {
        $fingerprint = DB::table('idempotency_keys')
            ->where('token_id', $tokenId)
            ->where('key', $key)
            ->where('expires_at', '>', $now)
            ->value('fingerprint');

        return is_string($fingerprint) ? $fingerprint : null;
    }

    /**
     * Bills the call, unless its Idempotency-Key was already billed for the same request.
     *
     * One transaction, holding a lock on the account's row: concurrent calls of the account take
     * turns, so the quota check and the count are exact, and a key is claimed by exactly one call.
     *
     * @return bool whether the call was billed (false: a repeat of an already-billed key)
     *
     * @throws ApiError quota_exceeded, or idempotency_key_reused for a key billed for another request
     */
    public function bill(BillableCall $call, CarbonInterface $now): bool
    {
        return DB::transaction(function () use ($call, $now): bool {
            DB::table('users')->where('id', $call->userId)->lockForUpdate()->value('id');

            if ($call->idempotencyKey !== null && ! $this->claim($call, $now)) {
                if ($this->billedFingerprint($call->tokenId, $call->idempotencyKey, $now) !== $call->fingerprint) {
                    throw new ApiError(ErrorCode::IdempotencyKeyReused);
                }

                return false;
            }

            // After the claim: if the quota stops this call, the rollback releases the key again.
            if ($this->usage($call->userId, $now) >= self::MONTHLY_QUOTA) {
                throw self::quotaExceeded($now);
            }

            DB::table('billable_calls')->insert([
                'user_id' => $call->userId,
                'token_id' => $call->tokenId,
                'endpoint' => $call->endpoint,
                'client' => $call->client,
                'idempotency_key' => $call->idempotencyKey,
                'created_at' => $now,
            ]);

            return true;
        });
    }

    public static function quotaExceeded(CarbonInterface $now): ApiError
    {
        $resetsAt = self::period($now)[1];

        return new ApiError(
            ErrorCode::QuotaExceeded,
            'This account has used its '.self::MONTHLY_QUOTA.' billable calls for '.$resetsAt->subMonth()->format('F Y').' (UTC). The quota resets at '.$resetsAt->toIso8601ZuluString().'.',
            headers: ['Retry-After' => (string) max(1, (int) ceil($resetsAt->getTimestamp() - (float) $now->format('U.u')))],
            resetsAt: $resetsAt->toIso8601ZuluString(),
        );
    }

    /**
     * Claims the key for this call: a new key, or one whose 24 hours are over. False if the key
     * is still held by an earlier billed call.
     */
    private function claim(BillableCall $call, CarbonInterface $now): bool
    {
        $claimed = DB::selectOne(
            'INSERT INTO idempotency_keys (token_id, key, fingerprint, created_at, expires_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (token_id, key) DO UPDATE
                SET fingerprint = EXCLUDED.fingerprint, created_at = EXCLUDED.created_at, expires_at = EXCLUDED.expires_at
                WHERE idempotency_keys.expires_at <= EXCLUDED.created_at
             RETURNING id',
            [$call->tokenId, $call->idempotencyKey, $call->fingerprint, $now, $now->copy()->addHours(self::IDEMPOTENCY_HOURS)],
        );

        return $claimed !== null;
    }

    /**
     * The billing period (UTC calendar month) containing $now: [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public static function period(CarbonInterface $now): array
    {
        $start = CarbonImmutable::instance($now)->utc()->startOfMonth();

        return [$start, $start->addMonth()];
    }
}
