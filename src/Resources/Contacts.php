<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;
use Postal\Exceptions\PostalException;

/**
 * Onde a documentação diz "ID do contato", vale também o e-mail ou `external_id:<id>`.
 */
class Contacts extends Resource
{
    /**
     * Cria um contato (`POST /contacts`). Falha se o e-mail ou o `external_id` já existirem.
     *
     * @param  array<string, mixed>  $params  `email` (obrigatório), `external_id`, `first_name`, `last_name`,
     *                                        `unsubscribed`, `properties`, `segments`, `topics` e `tags`.
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        return $this->client->request('POST', '/contacts', body: $params);
    }

    /**
     * Cria ou atualiza um contato, procurando por `id`, depois `external_id`, depois `email`
     * (`POST /contacts/batch` com um item). Repetir a chamada é seguro.
     *
     * ```php
     * $postal->contacts->upsert(['external_id' => 'user_42', 'email' => 'fulano@exemplo.com', 'first_name' => 'Fulano']);
     * ```
     *
     * @param  array<string, mixed>  $params
     * @return array{index: int, status: string, id: string, external_id: string|null, email: string, warnings?: list<string>}
     *                                                                                                                         `status` é `created`, `updated` ou `unchanged`.
     *
     * @throws PostalException `validation_error` se o item for recusado.
     */
    public function upsert(array $params): array
    {
        $response = $this->batch([$params]);
        $result = $response['results'][0] ?? null;

        if ($result === null) {
            throw new PostalException(502, 'invalid_response', 'O Postal não devolveu o resultado do contato.');
        }

        if (($result['status'] ?? null) === 'failed') {
            throw new PostalException(422, 'validation_error', (string) ($result['error'] ?? 'Não foi possível salvar o contato.'));
        }

        return $result;
    }

    /**
     * Cria ou atualiza vários contatos de uma vez (`POST /contacts/batch`). Até 1.000 itens a resposta traz
     * um resultado por item (um item com erro não derruba os outros); acima disso o lote vai para a fila.
     *
     * @param  list<array<string, mixed>>  $contacts
     * @return array<string, mixed>
     */
    public function batch(array $contacts): array
    {
        return $this->client->request('POST', '/contacts/batch', body: ['contacts' => array_values($contacts)], options: ['idempotent' => true]);
    }

    /**
     * Um contato pelo ID, e-mail ou `external_id:<id>` (`GET /contacts/{id}`).
     *
     * @return array<string, mixed>
     */
    public function get(string $idOrEmail): array
    {
        return $this->client->request('GET', '/contacts/'.self::segment($idOrEmail));
    }

    /**
     * Um contato pelo `external_id` (a ID dele no seu sistema).
     *
     * @return array<string, mixed>
     */
    public function getByExternalId(string $externalId): array
    {
        return $this->get('external_id:'.$externalId);
    }

    /**
     * Atualiza só os campos enviados (`PATCH /contacts/{id}`).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function update(string $idOrEmail, array $params): array
    {
        return $this->client->request('PATCH', '/contacts/'.self::segment($idOrEmail), body: $params);
    }

    /**
     * Remove o contato da audiência (`DELETE /contacts/{id}`). Para só parar de enviar, use `unsubscribed => true`.
     *
     * @return array<string, mixed>
     */
    public function delete(string $idOrEmail): array
    {
        return $this->client->request('DELETE', '/contacts/'.self::segment($idOrEmail));
    }

    /**
     * Uma página de contatos, do mais recente para o mais antigo (`GET /contacts`).
     * Filtros: `limit`, `after`, `before`, `search`, `segment_id`, `topic_id`, `unsubscribed`.
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
     * Percorre todos os contatos que batem com o filtro, página a página.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): Generator
    {
        return self::paginate($this->list(...), $params);
    }

    /**
     * Coloca o contato num segmento estático (`POST /contacts/{id}/segments/{segment_id}`).
     *
     * @return array<string, mixed>
     */
    public function addToSegment(string $idOrEmail, string $segmentId): array
    {
        return $this->client->request('POST', '/contacts/'.self::segment($idOrEmail).'/segments/'.self::segment($segmentId), body: []);
    }

    /**
     * Tira o contato de um segmento estático (`DELETE /contacts/{id}/segments/{segment_id}`).
     *
     * @return array<string, mixed>
     */
    public function removeFromSegment(string $idOrEmail, string $segmentId): array
    {
        return $this->client->request('DELETE', '/contacts/'.self::segment($idOrEmail).'/segments/'.self::segment($segmentId));
    }

    /**
     * Registra a escolha do contato em tópicos (`PATCH /contacts/{id}/topics`).
     *
     * @param  list<array{id: string, subscription: string}>  $topics  `subscription` é `opt_in` ou `opt_out`.
     * @return array<string, mixed>
     */
    public function updateTopics(string $idOrEmail, array $topics): array
    {
        return $this->client->request('PATCH', '/contacts/'.self::segment($idOrEmail).'/topics', body: ['topics' => array_values($topics)]);
    }
}
