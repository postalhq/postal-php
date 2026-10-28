<?php

declare(strict_types=1);

namespace Postal\Tests;

use Closure;
use Postal\Http\Transport;

/**
 * Fake HTTP transport: keeps the requests and answers with whatever the test sets up.
 */
final class FakeTransport implements Transport
{
    /**
     * @var list<array{method: string, url: string, path: string, headers: array<string, string>, body: string|null, json: mixed, timeout: float, connect_timeout: float}>
     */
    public array $requests = [];

    /**
     * @param  Closure(array{method: string, url: string, path: string, headers: array<string, string>, body: string|null, json: mixed, timeout: float, connect_timeout: float}, int): array{status?: int, body?: mixed, headers?: array<string, string>}  $handler
     */
    public function __construct(private readonly Closure $handler) {}

    public static function replying(array $body, int $status = 200): self
    {
        return new self(fn (): array => ['status' => $status, 'body' => $body]);
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout = 5.0): array
    {
        $request = [
            'method' => $method,
            'url' => $url,
            'path' => (string) preg_replace('#^https?://[^/]+#', '', $url),
            'headers' => $headers,
            'body' => $body,
            'json' => $body === null ? null : json_decode($body, true),
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
        ];
        $this->requests[] = $request;

        $reply = ($this->handler)($request, count($this->requests));
        $payload = $reply['body'] ?? [];

        return [
            'status' => $reply['status'] ?? 200,
            'headers' => $reply['headers'] ?? [],
            'body' => is_string($payload) ? $payload : json_encode($payload, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * "METHOD /path" of each request.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_map(fn (array $request): string => $request['method'].' '.$request['path'], $this->requests);
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers?: array<string, string>}
     */
    public static function error(int $status, string $name, string $message, array $headers = []): array
    {
        return ['status' => $status, 'body' => ['statusCode' => $status, 'name' => $name, 'message' => $message], 'headers' => $headers];
    }
}
