<?php

declare(strict_types=1);

namespace Postal\Resources;

use Postal\Webhook;

/**
 * Registration of webhook endpoints. To verify the signature of a delivery, use {@see Webhook::verify()}.
 */
class Webhooks extends Resource
{
    /**
     * @param  array<string, mixed>  $params  `limit`, `after`, `before`.
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/webhooks', $params);
    }

    /**
     * Returns the `signing_secret` (`whsec_...`) used by {@see Webhook::verify()}.
     *
     * @param  array{endpoint: string, events: list<string>}  $params
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/webhooks', body: $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/webhooks/'.self::segment($id));
    }

    /**
     * @param  array{endpoint?: string, events?: list<string>, status?: string}  $params
     * @return array<string, mixed>
     */
    public function update(string $id, array $params): array
    {
        return $this->client->request('PATCH', '/webhooks/'.self::segment($id), body: $params);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $id): array
    {
        return $this->client->request('DELETE', '/webhooks/'.self::segment($id));
    }

    /**
     * Generates a new secret, valid right away. Update your server afterwards.
     *
     * @return array<string, mixed>
     */
    public function rotateSecret(string $id): array
    {
        return $this->client->request('POST', '/webhooks/'.self::segment($id).'/signing-secret/rotate', body: []);
    }
}
