<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Events extends Resource
{
    /**
     * Sends an event (`POST /events`) and starts the automations that use that name as a trigger. Works with
     * a sending-only key. Repeating with the same `$idempotencyKey` does not record the event again.
     *
     * Fields: `name` (required), `contact` (email, ID, `external_id:<id>` or array), `external_id`,
     * `properties`, `contact_properties` and `occurred_at` (ISO 8601 or `DateTimeInterface`).
     *
     * ```php
     * $postal->events->send(['name' => 'signup', 'contact' => 'jane@example.com', 'properties' => ['plan' => 'free']]);
     * ```
     *
     * @param  array<string, mixed>  $params
     * @return array{object: string, id: string, contact_id: string|null}
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        if (array_key_exists('occurred_at', $params)) {
            $params['occurred_at'] = self::isoDate($params['occurred_at']);
        }

        /** @var array{object: string, id: string, contact_id: string|null} */
        return $this->client->request('POST', '/events', body: $params, options: ['idempotency_key' => $idempotencyKey, 'auto_idempotency' => true]);
    }

    /**
     * A page of events from the last 90 days (`GET /events`). Needs a full-access key.
     * Filters: `limit`, `after`, `before`, `name` (text or list), `contact`.
     *
     * @param  array<string, mixed>  $params
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/events', $params);
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
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/events/'.self::segment($id));
    }

    /**
     * The event names the account has already sent (`GET /events/names`).
     *
     * @return array<string, mixed>
     */
    public function names(): array
    {
        return $this->client->request('GET', '/events/names');
    }
}
