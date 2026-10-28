<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Emails extends Resource
{
    /**
     * Sends an email (`POST /emails`).
     *
     * Fields: `from`, `to`, `subject`, `html`/`text` (or `template`), `cc`, `bcc`, `reply_to`, `headers`,
     * `tags`, `attachments` and `scheduled_at` (ISO 8601 or `DateTimeInterface`).
     *
     * Without `$idempotencyKey`, the SDK generates a key for this call and reuses it across retries, so
     * a repeat after a timeout or a 5xx error does not duplicate the send.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string}
     */
    public function send(array $params, ?string $idempotencyKey = null): array
    {
        if (array_key_exists('scheduled_at', $params)) {
            $params['scheduled_at'] = self::isoDate($params['scheduled_at']);
        }

        /** @var array{id: string} */
        return $this->client->request('POST', '/emails', body: $params, options: $this->idempotency($idempotencyKey));
    }

    /**
     * Sends up to 100 emails in one request (`POST /emails/batch`). Either all are queued, or none.
     *
     * @param  list<array<string, mixed>>  $emails
     * @return array{data: list<array{id: string}>}
     */
    public function batch(array $emails, ?string $idempotencyKey = null): array
    {
        /** @var array{data: list<array{id: string}>} */
        return $this->client->request('POST', '/emails/batch', body: array_values($emails), options: $this->idempotency($idempotencyKey));
    }

    /**
     * One email with its content and event history (`GET /emails/{id}`).
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/emails/'.self::segment($id));
    }

    /**
     * A page of emails, newest to oldest (`GET /emails`). Needs a full-access key.
     *
     * Filters: `limit`, `after`, `before`, `search`, `status` (text or list), `domain_id`, `api_key_id`,
     * `start_date`, `end_date`.
     *
     * @param  array<string, mixed>  $params
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/emails', $params);
    }

    /**
     * Walks through all emails matching the filter, page by page.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): Generator
    {
        return self::paginate($this->list(...), $params);
    }

    /**
     * Changes the date of a scheduled email (`PATCH /emails/{id}`).
     *
     * @return array<string, mixed>
     */
    public function update(string $id, mixed $scheduledAt): array
    {
        return $this->client->request('PATCH', '/emails/'.self::segment($id), body: ['scheduled_at' => self::isoDate($scheduledAt)]);
    }

    /**
     * Cancels a scheduled email (`POST /emails/{id}/cancel`).
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/emails/'.self::segment($id).'/cancel', body: []);
    }

    /**
     * The message as it was sent, in RFC 5322 format (`GET /emails/{id}/raw`).
     */
    public function raw(string $id): string
    {
        return $this->client->requestText('GET', '/emails/'.self::segment($id).'/raw');
    }

    /**
     * @return array{idempotency_key?: string|null, auto_idempotency: bool}
     */
    private function idempotency(?string $key): array
    {
        return ['idempotency_key' => $key, 'auto_idempotency' => true];
    }
}
