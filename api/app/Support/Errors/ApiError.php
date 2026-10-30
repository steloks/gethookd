<?php

namespace App\Support\Errors;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * An error in the one shape every non-v0 API response uses:
 * {"error": {"code", "message", "next_step", "retryable", "retry_after"?}}.
 *
 * Throw it from anywhere in a request; the exception handler renders it.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        ?string $message = null,
        public readonly ?string $nextStep = null,
        public readonly ?int $retryAfter = null,
        public readonly array $headers = [],
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

        if ($this->retryAfter !== null) {
            $error['retry_after'] = $this->retryAfter;
        }

        return new JsonResponse(['error' => $error], $this->errorCode->status(), $this->headers);
    }
}
