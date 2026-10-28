<?php

declare(strict_types=1);

namespace Postal;

use Postal\Exceptions\WebhookVerificationException;

/**
 * Verifies the signature of Postal webhook deliveries (Standard Webhooks).
 */
final class Webhook
{
    /**
     * Verifies the signature of a delivery and returns the decoded event (`type`, `created_at`, `data`).
     *
     * The signature is `base64(HMAC-SHA256(key, "{webhook-id}.{webhook-timestamp}.{body}"))`, sent in the
     * `webhook-signature` header as `v1,<base64>` (the same values come in `svix-id`, `svix-timestamp`
     * and `svix-signature`). The key is the `signing_secret` (`whsec_...`) without the prefix, Base64-decoded.
     *
     * Pass the **raw** body, exactly as it arrived (`file_get_contents('php://input')` or
     * `$request->getContent()`): if it went through `json_decode` and `json_encode`, the signature will not match.
     *
     * ```php
     * $event = Postal\Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('POSTAL_WEBHOOK_SECRET'));
     * ```
     *
     * @param  array<string, string|list<string>|null>  $headers  Request headers, in any letter case (`getallheaders()`,
     *                                                            `$request->headers->all()`).
     * @param  int  $tolerance  Maximum difference accepted between the `webhook-timestamp` and now, in seconds.
     * @param  int|null  $now  Clock, in Unix seconds. For tests only.
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException If anything does not check out.
     */
    public static function verify(string $payload, array $headers, string $secret, int $tolerance = 300, ?int $now = null): array
    {
        $lookup = [];

        foreach ($headers as $name => $value) {
            if ($value === null) {
                continue;
            }

            $lookup[strtolower((string) $name)] = is_array($value) ? implode(' ', $value) : (string) $value;
        }

        $id = $lookup['webhook-id'] ?? $lookup['svix-id'] ?? '';
        $timestamp = $lookup['webhook-timestamp'] ?? $lookup['svix-timestamp'] ?? '';
        $signatures = $lookup['webhook-signature'] ?? $lookup['svix-signature'] ?? '';

        if ($id === '' || $timestamp === '' || $signatures === '') {
            throw new WebhookVerificationException('The webhook-id, webhook-timestamp or webhook-signature headers are missing.');
        }

        if (! ctype_digit($timestamp)) {
            throw new WebhookVerificationException('The webhook-timestamp is invalid.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('The webhook-timestamp is outside the tolerance (old or repeated message).');
        }

        if ($secret === '') {
            throw new WebhookVerificationException('Provide the webhook signing_secret (whsec_...).');
        }

        $key = base64_decode(preg_replace('/^whsec_/', '', $secret) ?? '', true);

        if ($key === false) {
            throw new WebhookVerificationException('The signing_secret is not valid Base64 (expected: whsec_...).');
        }

        $expected = hash_hmac('sha256', "{$id}.{$timestamp}.{$payload}", $key, true);
        $valid = false;

        foreach (preg_split('/\s+/', trim($signatures)) ?: [] as $entry) {
            [$version, $signature] = array_pad(explode(',', $entry, 2), 2, '');

            if ($version !== 'v1') {
                continue;
            }

            $received = base64_decode($signature, true);

            if ($received !== false && hash_equals($expected, $received)) {
                $valid = true;
            }
        }

        if (! $valid) {
            throw new WebhookVerificationException('The webhook signature does not match.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new WebhookVerificationException('The webhook body is not valid JSON.');
        }

        return $event;
    }
}
