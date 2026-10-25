<?php

declare(strict_types=1);

namespace Postal;

use Postal\Exceptions\WebhookVerificationException;

/**
 * Confere a assinatura das entregas de webhook do Postal (Standard Webhooks).
 */
final class Webhook
{
    /**
     * Confere a assinatura de uma entrega e devolve o evento decodificado (`type`, `created_at`, `data`).
     *
     * A assinatura é `base64(HMAC-SHA256(chave, "{webhook-id}.{webhook-timestamp}.{corpo}"))`, enviada no
     * cabeçalho `webhook-signature` como `v1,<base64>` (os mesmos valores vêm em `svix-id`, `svix-timestamp`
     * e `svix-signature`). A chave é o `signing_secret` (`whsec_...`) sem o prefixo, decodificado de Base64.
     *
     * Passe o corpo **cru**, exatamente como chegou (`file_get_contents('php://input')` ou
     * `$request->getContent()`): se ele passou por `json_decode` e `json_encode`, a assinatura não bate.
     *
     * ```php
     * $event = Postal\Webhook::verify(file_get_contents('php://input'), getallheaders(), getenv('POSTAL_WEBHOOK_SECRET'));
     * ```
     *
     * @param  array<string, string|list<string>|null>  $headers  Cabeçalhos da requisição, em qualquer caixa (`getallheaders()`,
     *                                                            `$request->headers->all()`).
     * @param  int  $tolerance  Diferença máxima aceita entre o `webhook-timestamp` e agora, em segundos.
     * @param  int|null  $now  Relógio, em segundos Unix. Só para testes.
     * @return array<string, mixed>
     *
     * @throws WebhookVerificationException Se algo não conferir.
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
            throw new WebhookVerificationException('Faltam os cabeçalhos webhook-id, webhook-timestamp ou webhook-signature.');
        }

        if (! ctype_digit($timestamp)) {
            throw new WebhookVerificationException('O webhook-timestamp é inválido.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookVerificationException('O webhook-timestamp está fora da tolerância (mensagem antiga ou repetida).');
        }

        if ($secret === '') {
            throw new WebhookVerificationException('Informe o signing_secret do webhook (whsec_...).');
        }

        $key = base64_decode(preg_replace('/^whsec_/', '', $secret) ?? '', true);

        if ($key === false) {
            throw new WebhookVerificationException('O signing_secret não está em Base64 (esperado: whsec_...).');
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
            throw new WebhookVerificationException('A assinatura do webhook não confere.');
        }

        $event = json_decode($payload, true);

        if (! is_array($event)) {
            throw new WebhookVerificationException('O corpo do webhook não é um JSON válido.');
        }

        return $event;
    }
}
