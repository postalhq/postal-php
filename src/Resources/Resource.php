<?php

declare(strict_types=1);

namespace Postal\Resources;

use DateTimeInterface;
use Generator;
use Postal\Client;

/**
 * Base dos recursos da API.
 */
abstract class Resource
{
    public function __construct(protected readonly Client $client) {}

    /**
     * Codifica um ID ou e-mail para ir no caminho da URL.
     */
    protected static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Converte `DateTimeInterface` em ISO 8601 e deixa o resto como está.
     */
    protected static function isoDate(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
    }

    /**
     * Percorre todas as páginas de uma lista, de 100 em 100, seguindo `after` até `has_more` ser falso.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $fetchPage
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    protected static function paginate(callable $fetchPage, array $params): Generator
    {
        $after = $params['after'] ?? null;
        $params['limit'] = 100;

        while (true) {
            if ($after !== null) {
                $params['after'] = $after;
            }

            $page = $fetchPage($params);
            $items = $page['data'] ?? [];

            foreach ($items as $item) {
                yield $item;
            }

            $last = $items === [] ? null : $items[array_key_last($items)];

            if (! ($page['has_more'] ?? false) || $last === null) {
                return;
            }

            $after = $last['id'];
        }
    }
}
