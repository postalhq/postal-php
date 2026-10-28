<?php

declare(strict_types=1);

namespace Postal\Exceptions;

use Postal\RateLimit;
use RuntimeException;
use Throwable;

/**
 * Error returned by the Postal API (any status outside 2xx).
 *
 * `getErrorName()` is Postal's `name`, a stable code in English (`validation_error`,
 * `rate_limit_exceeded`, `invalid_api_key`...) that should be used to handle the error; `getMessage()` is
 * Postal's `message`, written for people, and may change.
 */
class PostalException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body  The error body, with extra fields such as `errors`.
     * @param  RateLimit|null  $rateLimit  The request limit from the response headers (on a 429, with `Retry-After`).
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorName,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly ?array $body = null,
        public readonly ?RateLimit $rateLimit = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * HTTP status of the response (0 when there was no response).
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * Postal's `name`, such as `validation_error`.
     */
    public function getErrorName(): string
    {
        return $this->errorName;
    }
}
