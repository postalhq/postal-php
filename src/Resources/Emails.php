<?php

declare(strict_types=1);

namespace Postal\Resources;

use Generator;

class Emails extends Resource
{
    /**
     * Envia um e-mail (`POST /emails`).
     *
     * Campos: `from`, `to`, `subject`, `html`/`text` (ou `template`), `cc`, `bcc`, `reply_to`, `headers`,
     * `tags`, `attachments` e `scheduled_at` (ISO 8601 ou `DateTimeInterface`).
     *
     * Sem `$idempotencyKey`, o SDK gera uma chave para esta chamada e a reaproveita nas retentativas, então
     * uma repetição após timeout ou erro 5xx não duplica o envio.
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
     * Envia até 100 e-mails numa requisição (`POST /emails/batch`). Ou todos entram na fila, ou nenhum.
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
     * Um e-mail com o conteúdo e o histórico de eventos (`GET /emails/{id}`).
     *
     * @return array<string, mixed>
     */
    public function get(string $id): array
    {
        return $this->client->request('GET', '/emails/'.self::segment($id));
    }

    /**
     * Uma página de e-mails, do mais recente para o mais antigo (`GET /emails`). Exige chave de acesso total.
     *
     * Filtros: `limit`, `after`, `before`, `search`, `status` (texto ou lista), `domain_id`, `api_key_id`,
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
     * Percorre todos os e-mails que batem com o filtro, página a página.
     *
     * @param  array<string, mixed>  $params
     * @return Generator<int, array<string, mixed>>
     */
    public function iterate(array $params = []): Generator
    {
        return self::paginate($this->list(...), $params);
    }

    /**
     * Muda a data de um e-mail agendado (`PATCH /emails/{id}`).
     *
     * @return array<string, mixed>
     */
    public function update(string $id, mixed $scheduledAt): array
    {
        return $this->client->request('PATCH', '/emails/'.self::segment($id), body: ['scheduled_at' => self::isoDate($scheduledAt)]);
    }

    /**
     * Cancela um e-mail agendado (`POST /emails/{id}/cancel`).
     *
     * @return array<string, mixed>
     */
    public function cancel(string $id): array
    {
        return $this->client->request('POST', '/emails/'.self::segment($id).'/cancel', body: []);
    }

    /**
     * A mensagem como foi enviada, em formato RFC 5322 (`GET /emails/{id}/raw`).
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
