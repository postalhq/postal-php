<?php

declare(strict_types=1);

namespace Postal;

use Postal\Exceptions\NetworkException;
use Postal\Exceptions\PostalException;
use Postal\Http\CurlTransport;
use Postal\Http\Transport;
use Postal\Resources\Contacts;
use Postal\Resources\Domains;
use Postal\Resources\Emails;
use Postal\Resources\Events;
use Postal\Resources\Segments;
use Postal\Resources\Topics;
use Postal\Resources\Webhooks;

/**
 * Postal API client.
 *
 * Input and output fields have the same names as the API (snake_case), so the documentation at
 * https://postal.so/en/docs/api applies directly to the SDK. Responses are arrays.
 *
 * ```php
 * $postal = new Postal\Client(getenv('POSTAL_API_KEY'));
 *
 * $postal->emails->send([
 *     'from' => 'Acme Store <you@yourdomain.com>',
 *     'to' => 'jane@example.com',
 *     'subject' => 'Hello',
 *     'html' => '<p>Welcome!</p>',
 * ]);
 * ```
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.postal.so';

    /**
     * A 429 that retrying will not fix: the plan quota ran out.
     */
    private const NON_RETRYABLE_429 = ['daily_quota_exceeded', 'monthly_quota_exceeded', 'ai_credits_exhausted'];

    private const MAX_RETRY_DELAY_MS = 30_000;

    public readonly Emails $emails;

    public readonly Contacts $contacts;

    public readonly Events $events;

    public readonly Segments $segments;

    public readonly Topics $topics;

    public readonly Domains $domains;

    public readonly Webhooks $webhooks;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly int $maxRetries;

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly int $retryDelayMs;

    private readonly Transport $transport;

    private readonly string $userAgent;

    private ?RateLimit $rateLimit = null;

    /**
     * @param  string|null  $apiKey  API key (`ps_...`). Without a key, uses the `POSTAL_API_KEY` environment variable.
     * @param  array{base_url?: string, max_retries?: int, timeout?: float|int, connect_timeout?: float|int, retry_delay?: int, transport?: Transport, user_agent?: string}  $options
     *                                                                                                                                                                                 Options: `max_retries` is the extra attempts on 429 and 5xx (default 2); `timeout`, the seconds per
     *                                                                                                                                                                                 attempt, from start to finish (default 30); `connect_timeout`, the seconds just to open the connection
     *                                                                                                                                                                                 (default 5; 0 turns it off); `retry_delay`, the base wait in milliseconds, which doubles on every attempt
     *                                                                                                                                                                                 (default 500). With the API down, the worst case is about
     *                                                                                                                                                                                 `(max_retries + 1) × connect_timeout` plus the waits between attempts.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey ??= (getenv('POSTAL_API_KEY') ?: null);

        if ($apiKey === null || $apiKey === '') {
            throw new PostalException(0, 'missing_api_key', 'Provide the API key: new Client("ps_...") or the POSTAL_API_KEY environment variable.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? (getenv('POSTAL_API_URL') ?: self::DEFAULT_BASE_URL), '/');
        $this->maxRetries = max(0, $options['max_retries'] ?? 2);
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->connectTimeout = max(0.0, (float) ($options['connect_timeout'] ?? 5));
        $this->retryDelayMs = max(0, $options['retry_delay'] ?? 500);
        $this->transport = $options['transport'] ?? new CurlTransport;
        $this->userAgent = 'postal-so-php/'.self::VERSION.(isset($options['user_agent']) ? ' '.$options['user_agent'] : '');

        $this->emails = new Emails($this);
        $this->contacts = new Contacts($this);
        $this->events = new Events($this);
        $this->segments = new Segments($this);
        $this->topics = new Topics($this);
        $this->domains = new Domains($this);
        $this->webhooks = new Webhooks($this);
    }

    /**
     * The request limit from the headers of the last response (`X-RateLimit-Limit`, `X-RateLimit-Remaining` and,
     * on a 429, `Retry-After` and `X-RateLimit-Reset`), including error responses.
     *
     * Works with a sending-only key and makes no extra request (unlike {@see usage()}). Returns `null`
     * until a response with those headers arrives; a response without them keeps the last known value.
     */
    public function rateLimit(): ?RateLimit
    {
        return $this->rateLimit;
    }

    /**
     * The account's plan, quotas and request limit (`GET /usage`). Works with a full-access or a sending-only key (a sending-only key does not see the credits).
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->request('GET', '/usage');
    }

    /**
     * Makes a request and returns the decoded JSON. Used by the resources.
     *
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body
     * @param  array{idempotency_key?: string|null, auto_idempotency?: bool, idempotent?: bool}  $options
     * @return array<string, mixed>
     *
     * @throws PostalException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): array
    {
        $response = $this->send($method, $path, $query, $body, $options);

        if ($response['body'] === '') {
            return [];
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new PostalException($response['status'], 'invalid_response', "Postal's response is not valid JSON.");
        }

        return $decoded;
    }

    /**
     * Returns the body as text (for `GET /emails/{id}/raw`).
     */
    public function requestText(string $method, string $path): string
    {
        return $this->send($method, $path, [], null, [])['body'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body
     * @param  array{idempotency_key?: string|null, auto_idempotency?: bool, idempotent?: bool}  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $path, array $query, ?array $body, array $options): array
    {
        $idempotencyKey = $options['idempotency_key'] ?? (($options['auto_idempotency'] ?? false) ? self::uuid() : null);
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        // Repeating a POST without an idempotency key after a 5xx or a network drop could duplicate the effect.
        $retriesServerErrors = in_array($method, ['GET', 'DELETE'], true) || ($options['idempotent'] ?? false) || $idempotencyKey !== null;
        $url = $this->baseUrl.$path.$this->queryString($query);
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $attempt < $this->maxRetries;

            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout, $this->connectTimeout);
            } catch (NetworkException $exception) {
                if ($canRetry && $retriesServerErrors) {
                    $this->sleep($this->delay($attempt));

                    continue;
                }

                throw $exception;
            }

            $this->rateLimit = RateLimit::fromHeaders($response['headers']) ?? $this->rateLimit;

            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response;
            }

            $error = $this->toException($response);
            $retryable = ($response['status'] === 429 && ! in_array($error->errorName, self::NON_RETRYABLE_429, true))
                || ($retriesServerErrors && ($response['status'] >= 500 || $error->errorName === 'concurrent_idempotent_requests'));

            if ($canRetry && $retryable) {
                $this->sleep($error->retryAfter !== null ? min($error->retryAfter * 1000, self::MAX_RETRY_DELAY_MS) : $this->delay($attempt));

                continue;
            }

            throw $error;
        }
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string}  $response
     */
    private function toException(array $response): PostalException
    {
        $retryAfterHeader = $response['headers']['retry-after'] ?? null;
        $retryAfter = $retryAfterHeader !== null && ctype_digit($retryAfterHeader) ? (int) $retryAfterHeader : null;
        $decoded = json_decode($response['body'], true);
        $body = is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded : null;

        return new PostalException(
            $response['status'],
            $body['name'] ?? ($response['status'] >= 500 ? 'internal_server_error' : 'unexpected_error'),
            is_string($body['message'] ?? null) ? $body['message'] : 'Erro '.$response['status'].' do Postal.',
            $retryAfter,
            $body,
            RateLimit::fromHeaders($response['headers']),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function queryString(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            $pairs[$key] = match (true) {
                is_array($value) => implode(',', $value),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $pairs === [] ? '' : '?'.http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private function delay(int $attempt): int
    {
        $base = $this->retryDelayMs * (2 ** $attempt);

        return (int) min($base + random_int(0, (int) ($base * 0.25)), self::MAX_RETRY_DELAY_MS);
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
