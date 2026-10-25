<?php

declare(strict_types=1);

namespace Postal\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Erro devolvido pela API do Postal (qualquer status fora de 2xx).
 *
 * `getErrorName()` é o `name` do Postal, um código estável em inglês (`validation_error`,
 * `rate_limit_exceeded`, `invalid_api_key`...) que deve ser usado para tratar o erro; `getMessage()` é
 * a `message` do Postal, escrita para pessoas, e pode mudar.
 */
class PostalException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body  O corpo do erro, com campos extras como `errors`.
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $errorName,
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly ?array $body = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * Status HTTP da resposta (0 quando não houve resposta).
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * O `name` do Postal, como `validation_error`.
     */
    public function getErrorName(): string
    {
        return $this->errorName;
    }
}
