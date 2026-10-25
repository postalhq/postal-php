<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Segments extends Resource
{
    /**
     * @param  array<string, mixed>  $params  `limit`, `after`, `before`, `search`.
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/segments', $params);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): Generator
    {
        return self::paginate($this->list(...), $params);
    }

    /**
     * @param  array{name: string, description?: string|null}  $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/segments', body: $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/segments/'.self::segment($id));
    }

    /**
     * @param  array{name?: string, description?: string|null}  $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', '/segments/'.self::segment($id), body: $params);
    }

    /**
     * Apaga só a lista: os contatos continuam na audiência.
     *
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        return $this->client->request('DELETE', '/segments/'.self::segment($id));
    }
}
