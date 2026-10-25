<?php

declare(strict_types=1);

/*
 * API falsa para o teste do CurlTransport (php -S). Responde com o que recebeu.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/slow') {
    sleep(3);
}

if (str_starts_with($path, '/limited')) {
    http_response_code(429);
    header('Content-Type: application/json');
    header('Retry-After: 7');
    echo json_encode(['statusCode' => 429, 'name' => 'rate_limit_exceeded', 'message' => 'Too many requests']);

    return;
}

header('Content-Type: application/json');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'idempotency_key' => $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'body' => file_get_contents('php://input'),
]);
