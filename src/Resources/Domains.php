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
     * The domain with its DNS records and the status of each.
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/domains/'.self::segment($id));
    }

    /**
     * Registers the domain and returns the DNS records to create. Then call {@see verify()}.
     *
     * @param  array<string, mixed>  $params  `name` (required), `region`, `custom_return_path`, `open_tracking`,
     *                                        `click_tracking`, `tracking_subdomain`, `tls` and `capabilities`.
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/domains', body: $params);
    }

    /**
     * Asks Postal to check the DNS now (`POST /domains/{id}/verify`). Follow the status with {@see get()}.
     *
     * @return array<string, mixed>
     */
    public function verify(string $id): array
    {
        return $this->client->request('POST', '/domains/'.self::segment($id).'/verify', body: []);
    }
}
