<?php

declare(strict_types=1);

namespace Postal;

/**
 * Requests-per-second limit read from the headers of a Postal response.
 *
 * Every response carries `X-RateLimit-Limit` and `X-RateLimit-Remaining`; a `429` also carries `Retry-After`
 * and `X-RateLimit-Reset`. Missing fields are `null`. It does not need a full-access key
 * (unlike `Client::usage()`) and makes no extra request.
 */
final class RateLimit
{
    /**
     * @param  int|null  $limit  Requests allowed per second (`X-RateLimit-Limit`).
     * @param  int|null  $remaining  How many still fit in the current window (`X-RateLimit-Remaining`).
     * @param  int|null  $retryAfter  Seconds to wait before trying again (`Retry-After`, only on a 429).
     * @param  int|null  $resetAt  When the limit comes back, as a Unix timestamp (`X-RateLimit-Reset`, only on a 429).
     */
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?int $retryAfter = null,
        public readonly ?int $resetAt = null,
    ) {}

    /**
     * Reads the headers (lowercase names). Returns `null` when none of them came.
     *
     * @param  array<string, string>  $headers
     */
    public static function fromHeaders(array $headers): ?self
    {
        $rateLimit = new self(
            self::integer($headers['x-ratelimit-limit'] ?? null),
            self::integer($headers['x-ratelimit-remaining'] ?? null),
            self::integer($headers['retry-after'] ?? null),
            self::integer($headers['x-ratelimit-reset'] ?? null),
        );

        return $rateLimit->limit === null && $rateLimit->remaining === null && $rateLimit->retryAfter === null && $rateLimit->resetAt === null
            ? null
            : $rateLimit;
    }

    private static function integer(?string $value): ?int
    {
        return $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }
}
