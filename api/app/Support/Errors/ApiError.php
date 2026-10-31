<?php

namespace App\Support\Errors;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An error in the one shape every non-v0 API response uses:
 * {"error": {"code", "message", "next_step", "retryable", "details"?, "retry_after"?, "resets_at"?}}.
 *
 * Throw it from anywhere in a request; the exception handler renders it.
 */
final class ApiError extends RuntimeException
{
    /** At most this many details are sent, so a request with thousands of bad parameters can't get a huge response. */
    public const MAX_DETAILS = 10;

    /**
     * @param  array<string, string>  $headers
     * @param  list<array<string, mixed>>  $details  one entry per problem, e.g. per invalid parameter; only the first MAX_DETAILS are sent, so the message should say how many there are
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly ?string $nextStep = null,
        public readonly ?int $retryAfter = null,
        public readonly array $headers = [],
        public readonly array $details = [],
        public readonly ?string $resetsAt = null,
    ) {
        parent::__construct($message ?? $errorCode->message());
    }

    public function toResponse(): JsonResponse
    {
        $error = [
            'code' => $this->errorCode->value,
            'message' => $this->getMessage(),
            'next_step' => $this->nextStep ?? $this->errorCode->nextStep(),
            'retryable' => $this->errorCode->retryable(),
        ];

        if ($this->details !== []) {
            $error['details'] = array_slice($this->details, 0, self::MAX_DETAILS);
        }

        if ($this->retryAfter !== null) {
            $error['retry_after'] = $this->retryAfter;
        }

        if ($this->resetsAt !== null) {
            $error['resets_at'] = $this->resetsAt;
        }

        return new JsonResponse(['error' => $error], $this->errorCode->status(), $this->headers);
    }
}
