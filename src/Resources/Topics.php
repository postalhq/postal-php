<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Topics extends Resource
{
    /**
     * @param  array<string, mixed>  $params  `limit`, `after`, `before`.
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/topics', $params);
    }

    /**
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(): Generator
    {
        return self::paginate($this->list(...), []);
    }

    /**
     * @param  array{name: string, default_subscription: string, description?: string|null, visibility?: string}  $params
     *                                                                                                                     `default_subscription` é `opt_in` ou `opt_out` e não muda depois de criado.
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/topics', body: $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/topics/'.self::segment($id));
    }

    /**
     * @param  array{name?: string, description?: string|null, visibility?: string}  $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', '/topics/'.self::segment($id), body: $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        return $this->client->request('DELETE', '/topics/'.self::segment($id));
    }
}
