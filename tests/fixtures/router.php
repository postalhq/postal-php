<?php

declare(strict_types=1);

/*
 * Fake API for the CurlTransport test (php -S). Answers with what it received.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/slow') {
    sleep(3);
}

if (str_starts_with($path, '/limited')) {
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 7');
    header('X-RateLimit-Limit: 10');
    header('X-RateLimit-Remaining: 0');
    header('X-RateLimit-Reset: 1790000000');
    echo json_encode(['statusCode' => 429, 'name' => 'rate_limit_exceeded', 'message' => 'Too many requests']);

    return;
}

header('Content-Type: application/json');
header('X-RateLimit-Limit: 10');
header('X-RateLimit-Remaining: 9');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => file_get_contents('php://input'),
]);
