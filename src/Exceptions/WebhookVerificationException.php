<?php

declare(strict_types=1);

namespace Postal\Exceptions;

/**
 * A webhook signature does not match. The name is `webhook_verification_failed`.
 */
class WebhookVerificationException extends PostalException
{
    public function __construct(string $message)
    {
        parent::__construct(400, 'webhook_verification_failed', $message);
    }
}
