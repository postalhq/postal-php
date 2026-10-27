<?php

declare(strict_types=1);

namespace Postal;

/**
 * Limite de requisições por segundo lido dos cabeçalhos de uma resposta do Postal.
 *
 * Toda resposta traz `X-RateLimit-Limit` e `X-RateLimit-Remaining`; a `429` traz também `Retry-After`
 * e `X-RateLimit-Reset`. Os campos ausentes ficam `null`. Não exige chave de acesso total
 * (diferente de `Client::usage()`) e não faz requisição extra.
 */
final class RateLimit
{
    /**
     * @param  int|null  $limit  Requisições permitidas por segundo (`X-RateLimit-Limit`).
     * @param  int|null  $remaining  Quantas ainda cabem na janela atual (`X-RateLimit-Remaining`).
     * @param  int|null  $retryAfter  Segundos para esperar antes de tentar de novo (`Retry-After`, só na 429).
     * @param  int|null  $resetAt  Quando o limite volta, em timestamp Unix (`X-RateLimit-Reset`, só na 429).
     */
    public function __construct(
        public readonly ?int $limit = null,
        public readonly ?int $remaining = null,
        public readonly ?int $retryAfter = null,
        public readonly ?int $resetAt = null,
    ) {}

    /**
     * Lê os cabeçalhos (nomes em minúsculas). Devolve `null` quando nenhum deles veio.
     *
     * @param  array<string, string>  $headers
     */
    public static function fromHeaders(array $headers): ?self
    {
        $rateLimit = new self(
            self::integer($headers['x-ratelimit-limit'] ?? null),
            self::integer($headers['x-ratelimit-remaining'] ?? null),
            self::integer($headers['retry-after'] ?? null),
            self::integer($headers['x-ratelimit-reset'] ?? null),
        );

        return $rateLimit->limit === null && $rateLimit->remaining === null && $rateLimit->retryAfter === null && $rateLimit->resetAt === null
            ? null
            : $rateLimit;
    }

    private static function integer(?string $value): ?int
    {
        return $value !== null && ctype_digit(trim($value)) ? (int) trim($value) : null;
    }
}
