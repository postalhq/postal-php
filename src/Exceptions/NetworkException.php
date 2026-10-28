<?php

declare(strict_types=1);

namespace Postal\Exceptions;

use Throwable;

/**
 * Network failure or timeout: the API did not get to answer. The status is 0 and the name is
 * `network_error`, `timeout` (the API accepted the connection but did not answer in time)
 * or `connect_timeout` (the connection could not be opened in time).
 */
class NetworkException extends PostalException
{
    public function __construct(string $errorName, string $message, ?Throwable $previous = null)
    {
        parent::__construct(0, $errorName, $message, previous: $previous);
    }
}
