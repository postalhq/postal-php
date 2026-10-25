<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Events extends Resource
{
    /**
     * Envia um evento (`POST /events`) e inicia as automações que usam esse nome como gatilho. Funciona com
     * chave só de envio. Repetir com a mesma `$idempotencyKey` não registra o evento de novo.
     *
     * Campos: `name` (obrigatório), `contact` (e-mail, ID, `external_id:<id>` ou array), `external_id`,
     * `properties`, `contact_properties` e `occurred_at` (ISO 8601 ou `DateTimeInterface`).
     *
     * ```php
     * $postal->events->send(['name' => 'signup', 'contact' => 'fulano@exemplo.com', 'properties' => ['plan' => 'free']]);
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
     * Uma página de eventos dos últimos 90 dias (`GET /events`). Exige chave de acesso total.
     * Filtros: `limit`, `after`, `before`, `name` (texto ou lista), `contact`.
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
     * Os nomes de evento que a conta já enviou (`GET /events/names`).
     *
     * @return array<string, mixed>
     */
    public function names(): array
    {
        return $this->client->request('GET', '/events/names');
    }
}
