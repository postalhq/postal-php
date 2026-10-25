<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Domains extends Resource
{
    /**
     * @param  array<string, mixed>  $params  `limit`, `after`, `before`, `search`, `status`, `region`.
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/domains', $params);
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
     * O domínio com os registros DNS e o status de cada um.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/domains/'.self::segment($id));
    }

    /**
     * Cadastra o domínio e devolve os registros DNS a criar. Depois, chame {@see verify()}.
     *
     * @param  array<string, mixed>  $params  `name` (obrigatório), `region`, `custom_return_path`, `open_tracking`,
     *                                        `click_tracking`, `tracking_subdomain`, `tls` e `capabilities`.
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/domains', body: $params);
    }

    /**
     * Pede ao Postal que confira o DNS agora (`POST /domains/{id}/verify`). Acompanhe o status com {@see get()}.
     *
     * @return array<string, mixed>
     */
    public function verify(string $id): array
    {
        return $this->client->request('POST', '/domains/'.self::segment($id).'/verify', body: []);
    }
}
