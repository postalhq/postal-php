<?php

declare(strict_types=1);

namespace Postal\Tests;

use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Exceptions\NetworkException;
use Postal\Exceptions\PostalException;
use Postal\Http\CurlTransport;
use Postal\RateLimit;

/**
 * Testa o transporte de verdade (ext-curl) contra um servidor HTTP local, o `php -S`.
 */
final class CurlTransportTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;

    private static string $baseUrl = '';

    public static function setUpBeforeClass(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        self::$baseUrl = "http://127.0.0.1:{$port}";
        self::$process = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/fixtures/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $connection = @fsockopen('127.0.0.1', $port);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(100_000);
        }

        self::fail('O servidor de teste não subiu.');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    public function test_sends_json_with_the_authorization_and_idempotency_headers(): void
    {
        $transport = new CurlTransport;
        $response = $transport->send('POST', self::$baseUrl.'/emails', ['Authorization' => 'Bearer ps_x', 'Content-Type' => 'application/json', 'Idempotency-Key' => 'k1'], '{"a":1}', 5);
        $echo = json_decode($response['body'], true);

        $this->assertSame(200, $response['status']);
        $this->assertSame('application/json', $response['headers']['content-type']);
        $this->assertSame('POST', $echo['method']);
        $this->assertSame('Bearer ps_x', $echo['authorization']);
        $this->assertSame('k1', $echo['idempotency_key']);
        $this->assertSame('{"a":1}', $echo['body']);
    }

    public function test_the_client_works_end_to_end_over_curl(): void
    {
        $postal = new Client('ps_real', ['base_url' => self::$baseUrl, 'max_retries' => 0]);
        $response = $postal->contacts->list(['search' => 'ana', 'limit' => 5]);

        $this->assertSame('GET', $response['method']);
        $this->assertSame('/contacts?search=ana&limit=5', $response['uri']);
        $this->assertSame('Bearer ps_real', $response['authorization']);
        $this->assertEquals(new RateLimit(limit: 10, remaining: 9), $postal->rateLimit());
    }

    public function test_reads_the_retry_after_header_of_an_error(): void
    {
        $transport = new CurlTransport;
        $response = $transport->send('GET', self::$baseUrl.'/limited', [], null, 5);

        $this->assertSame(429, $response['status']);
        $this->assertSame('7', $response['headers']['retry-after']);

        $client = new Client('ps_x', ['base_url' => self::$baseUrl.'/limited', 'max_retries' => 0]);

        try {
            $client->usage();
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame('rate_limit_exceeded', $exception->getErrorName());
            $this->assertSame(7, $exception->retryAfter);
            $this->assertEquals(new RateLimit(limit: 10, remaining: 0, retryAfter: 7, resetAt: 1790000000), $exception->rateLimit);
            $this->assertEquals($exception->rateLimit, $client->rateLimit());
        }
    }

    public function test_a_timeout_becomes_a_network_exception(): void
    {
        try {
            (new CurlTransport)->send('GET', self::$baseUrl.'/slow', [], null, 0.3);
            $this->fail('Esperava uma exceção.');
        } catch (NetworkException $exception) {
            $this->assertSame('timeout', $exception->getErrorName());
        }
    }

    public function test_a_connect_timeout_is_reported_as_such_and_is_faster_than_the_total_timeout(): void
    {
        // Um servidor que nunca aceita, com fila de 1: depois que ela enche, o kernel descarta os SYN seguintes.
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['socket' => ['backlog' => 1]]));
        $this->assertNotFalse($server);
        $name = (string) stream_socket_get_name($server, false);
        $fillers = [];

        for ($index = 0; $index < 6; $index++) {
            $fillers[] = @stream_socket_client('tcp://'.$name, $code, $message, 0.1, STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT);
        }

        $started = microtime(true);

        try {
            (new CurlTransport)->send('GET', 'http://'.$name.'/', [], null, 10, 0.3);
            $this->markTestSkipped('Este ambiente não deixa a conexão pendente.');
        } catch (NetworkException $exception) {
            $this->assertSame('connect_timeout', $exception->getErrorName());
            $this->assertStringContainsString('connect_timeout', $exception->getMessage());
            $this->assertLessThan(3, microtime(true) - $started);
        } finally {
            fclose($server);
        }
    }

    public function test_the_connect_timeout_does_not_cut_a_slow_response_short(): void
    {
        try {
            (new CurlTransport)->send('GET', self::$baseUrl.'/slow', [], null, 0.3, 0.1);
            $this->fail('Esperava uma exceção.');
        } catch (NetworkException $exception) {
            $this->assertSame('timeout', $exception->getErrorName());
        }
    }

    public function test_a_refused_connection_becomes_a_network_exception(): void
    {
        $this->expectException(NetworkException::class);

        (new CurlTransport)->send('GET', 'http://127.0.0.1:1/', [], null, 2);
    }
}
