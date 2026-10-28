<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;
use Postal\Exceptions\PostalException;

/**
 * Where the documentation says "contact ID", the email or `external_id:<id>` works too.
 */
class Contacts extends Resource
{
    /**
     * Creates a contact (`POST /contacts`). Fails if the email or the `external_id` already exist.
     *
     * @param  array<string, mixed>  $params  `email` (required), `external_id`, `first_name`, `last_name`,
     *                                        `unsubscribed`, `properties`, `segments`, `topics` e `tags`.
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/contacts', body: $params);
    }

    /**
     * Creates or updates a contact, looking it up by `id`, then `external_id`, then `email`
     * (`POST /contacts/batch` with one item). Repeating the call is safe.
     *
     * ```php
     * $postal->contacts->upsert(['external_id' => 'user_42', 'email' => 'jane@example.com', 'first_name' => 'Jane']);
     * ```
     *
     * @param  array<string, mixed>  $params
     * @return array{index: int, status: string, id: string, external_id: string|null, email: string, warnings?: list<string>}
     *                                                                                                                         `status` is `created`, `updated` or `unchanged`.
     *
     * @throws PostalException `validation_error` if the item is refused.
     */
    public function upsert(array $params): array
    {
        $response = $this->batch([$params]);
        $result = $response['results'][0] ?? null;

        if ($result === null) {
            throw new PostalException(502, 'invalid_response', 'Postal did not return the contact result.');
        }

        if (($result['status'] ?? null) === 'failed') {
            throw new PostalException(422, 'validation_error', (string) ($result['error'] ?? 'The contact could not be saved.'));
        }

        return $result;
    }

    /**
     * Creates or updates several contacts at once (`POST /contacts/batch`). Up to 1,000 items, the response carries
     * one result per item (an item with an error does not bring down the others); above that the batch goes to the queue.
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, mixed>
     */
    public function batch(array $contacts): array
    {
        return $this->client->request('POST', '/contacts/batch', body: ['contacts' => array_values($contacts)], options: ['idempotent' => true]);
    }

    /**
     * One contact by ID, email or `external_id:<id>` (`GET /contacts/{id}`).
     *
     * @return array<string, mixed>
     */
    public function get(string $idOrEmail): array
    {
        return $this->client->request('GET', '/contacts/'.self::segment($idOrEmail));
    }

    /**
     * One contact by `external_id` (its ID in your system).
     *
     * @return array<string, mixed>
     */
    public function getByExternalId(string $externalId): array
    {
        return $this->get('external_id:'.$externalId);
    }

    /**
     * Updates only the fields sent (`PATCH /contacts/{id}`).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function update(string $idOrEmail, array $params): array
    {
        return $this->client->request('PATCH', '/contacts/'.self::segment($idOrEmail), body: $params);
    }

    /**
     * Removes the contact from the audience (`DELETE /contacts/{id}`). To just stop sending, use `unsubscribed => true`.
     *
     * @return array<string, mixed>
     */
    public function delete(string $idOrEmail): array
    {
        return $this->client->request('DELETE', '/contacts/'.self::segment($idOrEmail));
    }

    /**
     * A page of contacts, newest to oldest (`GET /contacts`).
     * Filters: `limit`, `after`, `before`, `search`, `segment_id`, `topic_id`, `unsubscribed`.
     *
     * @param  array<string, mixed>  $params
     * @return array{object: string, has_more: bool, data: list<array<string, mixed>>}
     */
    public function list(array $params = []): array
    {
        /** @var array{object: string, has_more: bool, data: list<array<string, mixed>>} */
        return $this->client->request('GET', '/contacts', $params);
    }

    /**
     * Walks through all contacts matching the filter, page by page.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): Generator
    {
        return self::paginate($this->list(...), $params);
    }

    /**
     * Puts the contact in a static segment (`POST /contacts/{id}/segments/{segment_id}`).
     *
     * @return array<string, mixed>
     */
    public function addToSegment(string $idOrEmail, string $segmentId): array
    {
        return $this->client->request('POST', '/contacts/'.self::segment($idOrEmail).'/segments/'.self::segment($segmentId), body: []);
    }

    /**
     * Takes the contact out of a static segment (`DELETE /contacts/{id}/segments/{segment_id}`).
     *
     * @return array<string, mixed>
     */
    public function removeFromSegment(string $idOrEmail, string $segmentId): array
    {
        return $this->client->request('DELETE', '/contacts/'.self::segment($idOrEmail).'/segments/'.self::segment($segmentId));
    }

    /**
     * Records the contact's choice on topics (`PATCH /contacts/{id}/topics`).
     *
     * @param  list<array{id: string, subscription: string}>  $topics  `subscription` is `opt_in` or `opt_out`.
     * @return array<string, mixed>
     */
    public function updateTopics(string $idOrEmail, array $topics): array
    {
        return $this->client->request('PATCH', '/contacts/'.self::segment($idOrEmail).'/topics', body: ['topics' => array_values($topics)]);
    }
}
