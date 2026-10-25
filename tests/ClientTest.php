<?php

declare(strict_types=1);

namespace Postal\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Exceptions\NetworkException;
use Postal\Exceptions\PostalException;
use Postal\Http\Transport;

final class ClientTest extends TestCase
{
    private function client(FakeTransport $transport, array $options = []): Client
    {
        return new Client('ps_test_key', ['transport' => $transport, 'retry_delay' => 0, ...$options]);
    }

    public function test_fails_without_an_api_key(): void
    {
        putenv('POSTAL_API_KEY');

        try {
            new Client;
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame('missing_api_key', $exception->getErrorName());
        }
    }

    public function test_reads_the_key_from_the_environment(): void
    {
        putenv('POSTAL_API_KEY=ps_from_env');
        $transport = FakeTransport::replying(['id' => 'e1']);

        try {
            (new Client(null, ['transport' => $transport]))->emails->send(['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 's', 'text' => 't']);
        } finally {
            putenv('POSTAL_API_KEY');
        }

        $this->assertSame('Bearer ps_from_env', $transport->requests[0]['headers']['Authorization']);
    }

    public function test_sends_an_email_with_bearer_json_and_idempotency_key(): void
    {
        $transport = FakeTransport::replying(['id' => 'em_1']);
        $result = $this->client($transport)->emails->send([
            'from' => 'Minha empresa <voce@seudominio.com.br>',
            'to' => ['fulano@exemplo.com'],
            'subject' => 'Olá',
            'html' => '<p>Oi</p>',
        ], 'order-1');
        $request = $transport->requests[0];

        $this->assertSame(['id' => 'em_1'], $result);
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.postal.so/emails', $request['url']);
        $this->assertSame('Bearer ps_test_key', $request['headers']['Authorization']);
        $this->assertSame('order-1', $request['headers']['Idempotency-Key']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertStringStartsWith('postal-so-php/', $request['headers']['User-Agent']);
        $this->assertStringContainsString('"subject":"Olá"', (string) $request['body']);
    }

    public function test_generates_an_idempotency_key_and_reuses_it_on_retries(): void
    {
        $transport = new FakeTransport(fn (array $request, int $count): array => $count === 1
            ? FakeTransport::error(500, 'internal_server_error', 'boom')
            : ['body' => ['id' => 'em_2']]);
        $result = $this->client($transport)->emails->send(['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 's', 'text' => 't']);
        $keys = array_map(fn (array $request): ?string => $request['headers']['Idempotency-Key'] ?? null, $transport->requests);

        $this->assertSame('em_2', $result['id']);
        $this->assertCount(2, $transport->requests);
        $this->assertNotNull($keys[0]);
        $this->assertSame($keys[0], $keys[1]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $keys[0]);
    }

    public function test_converts_dates_to_iso_8601(): void
    {
        $transport = FakeTransport::replying(['id' => 'em_3']);

        $this->client($transport)->emails->send([
            'from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 's', 'text' => 't',
            'scheduled_at' => new DateTimeImmutable('2026-10-26T12:00:00+00:00'),
        ]);

        $this->assertSame('2026-10-26T12:00:00+00:00', $transport->requests[0]['json']['scheduled_at']);
    }

    public function test_throws_a_postal_exception_with_name_message_and_status(): void
    {
        $transport = new FakeTransport(fn (): array => FakeTransport::error(422, 'validation_error', 'Missing `subject` field.'));

        try {
            $this->client($transport)->emails->send(['from' => 'a@b.com', 'to' => 'c@d.com', 'text' => 't']);
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame('validation_error', $exception->getErrorName());
            $this->assertSame('validation_error', $exception->errorName);
            $this->assertSame('Missing `subject` field.', $exception->getMessage());
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertCount(1, $transport->requests, '422 não é repetido');
    }

    public function test_retries_429_and_stops_after_the_limit(): void
    {
        $transport = new FakeTransport(fn (): array => FakeTransport::error(429, 'rate_limit_exceeded', 'Too many', ['retry-after' => '0']));

        try {
            $this->client($transport, ['max_retries' => 2])->contacts->get('fulano@exemplo.com');
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame('rate_limit_exceeded', $exception->getErrorName());
            $this->assertSame(0, $exception->retryAfter);
        }

        $this->assertCount(3, $transport->requests);
    }

    public function test_does_not_retry_exhausted_quotas(): void
    {
        $transport = new FakeTransport(fn (): array => FakeTransport::error(429, 'daily_quota_exceeded', 'Quota'));

        $this->expectException(PostalException::class);

        try {
            $this->client($transport)->emails->send(['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 's', 'text' => 't']);
        } finally {
            $this->assertCount(1, $transport->requests);
        }
    }

    public function test_retries_5xx_on_get_and_returns_the_success(): void
    {
        $transport = new FakeTransport(fn (array $request, int $count): array => $count < 3
            ? FakeTransport::error(503, 'ai_unavailable', 'busy')
            : ['body' => ['object' => 'list', 'has_more' => false, 'data' => []]]);
        $list = $this->client($transport)->domains->list();

        $this->assertSame([], $list['data']);
        $this->assertCount(3, $transport->requests);
    }

    public function test_does_not_retry_5xx_on_a_post_without_idempotency_key(): void
    {
        $transport = new FakeTransport(fn (): array => FakeTransport::error(500, 'internal_server_error', 'boom'));

        try {
            $this->client($transport)->contacts->create(['email' => 'fulano@exemplo.com']);
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame(500, $exception->getStatusCode());
        }

        $this->assertCount(1, $transport->requests);
    }

    public function test_network_errors_are_retried_on_get_and_then_thrown(): void
    {
        $transport = new class implements Transport
        {
            public int $calls = 0;

            public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
            {
                $this->calls++;

                throw new NetworkException('network_error', 'sem conexão');
            }
        };

        try {
            (new Client('ps_x', ['transport' => $transport, 'retry_delay' => 0]))->domains->list();
            $this->fail('Esperava uma exceção.');
        } catch (NetworkException $exception) {
            $this->assertSame('network_error', $exception->getErrorName());
            $this->assertSame(0, $exception->getStatusCode());
        }

        $this->assertSame(3, $transport->calls);
    }

    public function test_a_non_json_error_becomes_a_generic_exception(): void
    {
        $transport = new FakeTransport(fn (): array => ['status' => 502, 'body' => '<html>Bad gateway</html>']);

        try {
            $this->client($transport, ['max_retries' => 0])->domains->list();
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame(502, $exception->getStatusCode());
            $this->assertSame('internal_server_error', $exception->getErrorName());
        }
    }

    public function test_upsert_by_external_id_uses_contacts_batch_and_returns_the_item_result(): void
    {
        $transport = FakeTransport::replying([
            'object' => 'contact_batch', 'status' => 'completed', 'total' => 1,
            'results' => [['index' => 0, 'status' => 'created', 'id' => 'c1', 'external_id' => 'user_42', 'email' => 'fulano@exemplo.com']],
        ]);
        $result = $this->client($transport)->contacts->upsert(['external_id' => 'user_42', 'email' => 'fulano@exemplo.com', 'properties' => ['plan' => 'pro']]);

        $this->assertSame('created', $result['status']);
        $this->assertSame('c1', $result['id']);
        $this->assertSame('/contacts/batch', $transport->requests[0]['path']);
        $this->assertSame(['contacts' => [['external_id' => 'user_42', 'email' => 'fulano@exemplo.com', 'properties' => ['plan' => 'pro']]]], $transport->requests[0]['json']);
    }

    public function test_upsert_with_a_failed_item_throws_validation_error(): void
    {
        $transport = FakeTransport::replying([
            'object' => 'contact_batch', 'status' => 'completed', 'total' => 1,
            'results' => [['index' => 0, 'status' => 'failed', 'id' => null, 'error' => 'No contact has this external_id. Send its `email` to create it.']],
        ]);

        try {
            $this->client($transport)->contacts->upsert(['external_id' => 'nobody']);
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame('validation_error', $exception->getErrorName());
            $this->assertStringContainsString('Send its `email`', $exception->getMessage());
        }
    }

    public function test_encodes_emails_and_external_ids_in_the_path(): void
    {
        $transport = FakeTransport::replying(['object' => 'contact']);
        $client = $this->client($transport);

        $client->contacts->get('fulano+teste@exemplo.com');
        $client->contacts->getByExternalId('user 42');
        $client->contacts->delete('c1');

        $this->assertSame([
            'GET /contacts/fulano%2Bteste%40exemplo.com',
            'GET /contacts/external_id%3Auser%2042',
            'DELETE /contacts/c1',
        ], $transport->lines());
    }

    public function test_update_topics_and_segments(): void
    {
        $transport = FakeTransport::replying(['id' => 'c1']);
        $client = $this->client($transport);

        $client->contacts->update('c1', ['first_name' => 'Fulano']);
        $client->contacts->updateTopics('c1', [['id' => 't1', 'subscription' => 'opt_out']]);
        $client->contacts->addToSegment('c1', 's1');
        $client->contacts->removeFromSegment('c1', 's1');

        $this->assertSame(['PATCH /contacts/c1', 'PATCH /contacts/c1/topics', 'POST /contacts/c1/segments/s1', 'DELETE /contacts/c1/segments/s1'], $transport->lines());
        $this->assertSame(['topics' => [['id' => 't1', 'subscription' => 'opt_out']]], $transport->requests[1]['json']);
    }

    public function test_list_filters_go_in_the_query_string(): void
    {
        $transport = FakeTransport::replying(['object' => 'list', 'has_more' => false, 'data' => []]);

        $this->client($transport)->emails->list(['limit' => 5, 'status' => ['bounced', 'complained'], 'search' => null, 'unsubscribed' => false]);

        $this->assertSame('GET /emails?limit=5&status=bounced%2Ccomplained&unsubscribed=false', $transport->lines()[0]);
    }

    public function test_iterate_follows_the_after_cursor_until_has_more_is_false(): void
    {
        $transport = new FakeTransport(function (array $request): array {
            parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);

            return ['body' => isset($query['after'])
                ? ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'c']]]
                : ['object' => 'list', 'has_more' => true, 'data' => [['id' => 'a'], ['id' => 'b']]]];
        });
        $ids = [];

        foreach ($this->client($transport)->contacts->iterate(['search' => 'ana']) as $contact) {
            $ids[] = $contact['id'];
        }

        $this->assertSame(['a', 'b', 'c'], $ids);
        $this->assertSame(['GET /contacts?search=ana&limit=100', 'GET /contacts?search=ana&limit=100&after=b'], $transport->lines());
    }

    public function test_sends_an_event_with_contact_and_properties(): void
    {
        $transport = FakeTransport::replying(['object' => 'event', 'id' => 'ev1', 'contact_id' => 'c1'], 201);
        $result = $this->client($transport)->events->send(['name' => 'signup', 'contact' => 'fulano@exemplo.com', 'properties' => ['plan' => 'free']], 'signup-42');

        $this->assertSame('ev1', $result['id']);
        $this->assertSame('POST /events', $transport->lines()[0]);
        $this->assertSame('signup-42', $transport->requests[0]['headers']['Idempotency-Key']);
        $this->assertSame(['name' => 'signup', 'contact' => 'fulano@exemplo.com', 'properties' => ['plan' => 'free']], $transport->requests[0]['json']);
    }

    public function test_domains_emails_segments_topics_and_webhooks_endpoints(): void
    {
        $transport = new FakeTransport(fn (array $request): array => ['body' => str_ends_with($request['path'], '/raw') ? 'From: a@b.com' : ['data' => [['id' => 'x']]]]);
        $client = $this->client($transport);

        $client->domains->list(['status' => 'verified']);
        $client->domains->get('d1');
        $client->domains->verify('d1');
        $client->emails->get('e1');
        $client->emails->update('e1', '2026-10-26T09:00:00-03:00');
        $client->emails->cancel('e1');
        $client->emails->batch([['from' => 'a@b.com', 'to' => 'c@d.com', 'subject' => 's', 'text' => 't']]);
        $raw = $client->emails->raw('e1');
        $client->segments->create(['name' => 'Clientes']);
        $client->topics->create(['name' => 'Novidades', 'default_subscription' => 'opt_in']);
        $client->webhooks->create(['endpoint' => 'https://exemplo.com/hook', 'events' => ['email.delivered']]);
        $client->webhooks->rotateSecret('w1');
        $client->usage();

        $this->assertSame('From: a@b.com', $raw);
        $this->assertSame([
            'GET /domains?status=verified', 'GET /domains/d1', 'POST /domains/d1/verify',
            'GET /emails/e1', 'PATCH /emails/e1', 'POST /emails/e1/cancel', 'POST /emails/batch', 'GET /emails/e1/raw',
            'POST /segments', 'POST /topics', 'POST /webhooks', 'POST /webhooks/w1/signing-secret/rotate', 'GET /usage',
        ], $transport->lines());
        $this->assertTrue(array_is_list($transport->requests[6]['json']), 'o lote é uma lista');
    }
}
