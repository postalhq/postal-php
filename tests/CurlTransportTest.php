<?php

declare(strict_types=1);

namespace Postal\Tests;

use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Exceptions\NetworkException;
use Postal\Exceptions\PostalException;
use Postal\Http\CurlTransport;

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

    public function test_a_refused_connection_becomes_a_network_exception(): void
    {
        $this->expectException(NetworkException::class);

        (new CurlTransport)->send('GET', 'http://127.0.0.1:1/', [], null, 2);
    }
}
