<?php

declare(strict_types=1);

namespace Postal\Exceptions;

use Throwable;

/**
 * Falha de rede ou tempo esgotado: a API não chegou a responder. O status é 0 e o nome é
 * `network_error`, `timeout` (a API aceitou a conexão mas não respondeu a tempo)
 * ou `connect_timeout` (não foi possível abrir a conexão a tempo).
 */
class NetworkException extends PostalException
{
    public function __construct(string $errorName, string $message, ?Throwable $previous = null)
    {
        parent::__construct(0, $errorName, $message, previous: $previous);
    }
}
