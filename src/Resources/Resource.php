<?php

declare(strict_types=1);

namespace Postal\Resources;

use DateTimeInterface;
use Generator;
use Postal\Client;

/**
 * Base of the API resources.
 */
abstract class Resource
{
    public function __construct(protected readonly Client $client) {}

    /**
     * Encodes an ID or email to go in the URL path.
     */
    protected static function segment(string $value): string
    {
        return rawurlencode($value);
    }

    /**
     * Converts a `DateTimeInterface` to ISO 8601 and leaves the rest as it is.
     */
    protected static function isoDate(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(DateTimeInterface::ATOM) : $value;
    }

    /**
     * Walks through all the pages of a list, 100 at a time, following `after` until `has_more` is false.
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
