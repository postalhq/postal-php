<?php

declare(strict_types=1);

namespace Postal\Exceptions;

use Throwable;

/**
 * Falha de rede ou tempo esgotado: a API não chegou a responder. O status é 0 e o nome é
 * `network_error` ou `timeout`.
 */
class NetworkException extends PostalException
{
    public function __construct(string $errorName, string $message, ?Throwable $previous = null)
    {
        parent::__construct(0, $errorName, $message, previous: $previous);
    }
}
