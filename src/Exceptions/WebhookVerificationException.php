<?php

declare(strict_types=1);

namespace Postal\Exceptions;

/**
 * A assinatura de um webhook não confere. O nome é `webhook_verification_failed`.
 */
class WebhookVerificationException extends PostalException
{
    public function __construct(string $message)
    {
        parent::__construct(400, 'webhook_verification_failed', $message);
    }
}
