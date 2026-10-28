<?php

declare(strict_types=1);

namespace Postal\Http;

use Postal\Exceptions\NetworkException;

/**
 * Makes an HTTP request. The default is {@see CurlTransport}; swap in another implementation in tests.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @param  float  $timeout  Seconds for the whole request (connection, sending and response).
     * @param  float  $connectTimeout  Seconds just to open the connection (DNS, TCP and TLS); 0 turns off the dedicated limit.
     * @return array{status: int, headers: array<string, string>, body: string} Header names in lowercase.
     *
     * @throws NetworkException When the API did not answer (`network_error`, `timeout` or `connect_timeout`).
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout = 5.0): array;
}
