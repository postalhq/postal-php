<?php

declare(strict_types=1);

namespace Postal\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Exceptions\PostalException;
use Postal\OtpToken;

final class OtpTokenTest extends TestCase
{
    private const SEND = ['from' => 'voce@seudominio.com.br', 'to' => 'fulano@exemplo.com', 'subject' => 'Seu código', 'html' => '<p>Seu código: 123456</p>'];

    private function client(FakeTransport $transport): Client
    {
        return new Client('ps_test_key', ['transport' => $transport, 'retry_delay' => 0]);
    }

    public function test_builds_the_value_of_the_draft_example(): void
    {
        $this->assertSame('"123456"; origin="https://example.com"', OtpToken::header('123456', 'https://example.com'));
    }

    public function test_escapes_only_the_backslash_and_the_quote(): void
    {
        $this->assertSame('"a\\"b\\\\c d~"; origin="https://example.com:8443"', OtpToken::header('a"b\\c d~', 'https://example.com:8443'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function goodOrigins(): array
    {
        return array_map(fn (string $origin): array => [$origin], [
            'plain' => 'https://example.com',
            'subdomain' => 'https://app.example.com',
            'port' => 'https://example.com:8443',
            'punycode' => 'https://xn--exmple-cua.com',
            'localhost' => 'http://localhost',
            'localhost with port' => 'http://localhost:5173',
            'loopback address' => 'http://127.0.0.1:8000',
            'https localhost' => 'https://localhost:3000',
            'ipv4' => 'https://10.0.0.1',
            'ipv6' => 'https://[::1]',
        ]);
    }

    #[DataProvider('goodOrigins')]
    public function test_accepts_canonical_trustworthy_origins(string $origin): void
    {
        $this->assertSame("\"1\"; origin=\"{$origin}\"", OtpToken::header('1', $origin));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badOrigins(): array
    {
        return array_map(fn (string $origin): array => [$origin], [
            'path' => 'https://example.com/login',
            'trailing slash' => 'https://example.com/',
            'query' => 'https://example.com?x=1',
            'userinfo' => 'https://user@example.com',
            'uppercase host' => 'https://Example.com',
            'uppercase scheme' => 'HTTPS://example.com',
            'default https port' => 'https://example.com:443',
            'default http port' => 'http://localhost:80',
            'zero port' => 'https://example.com:0',
            'port too big' => 'https://example.com:65536',
            'leading zero port' => 'https://example.com:0443',
            'http outside localhost' => 'http://example.com',
            'http private address' => 'http://192.168.0.10',
            'null origin' => 'null',
            'empty' => '',
            'no scheme' => 'example.com',
            'other scheme' => 'ftp://example.com',
            'non ascii host' => 'https://exämple.com',
            'trailing dot' => 'https://example.com.',
            'empty label' => 'https://example..com',
            'hyphen at the edge' => 'https://-example.com',
            'underscore' => 'https://my_app.example.com',
            'numeric host' => 'https://123',
            'short ipv4' => 'https://127.1',
            'space' => 'https://exam ple.com',
            'ipv6 not compressed' => 'https://[0:0:0:0:0:0:0:1]',
        ]);
    }

    #[DataProvider('badOrigins')]
    public function test_refuses_origins_that_are_not_canonical_or_trustworthy(string $origin): void
    {
        try {
            OtpToken::header('123456', $origin);
            $this->fail('Esperava uma exceção para '.$origin);
        } catch (PostalException $exception) {
            $this->assertSame(0, $exception->getStatusCode());
            $this->assertSame('validation_error', $exception->getErrorName());
            $this->assertStringContainsString('origem do OTP-Token', $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function badCodes(): array
    {
        return array_map(fn (string $code): array => [$code], [
            'empty' => '',
            'line break' => "12\n34",
            'tab' => "12\t34",
            'non ascii' => '12é34',
            'delete' => "12\x7F34",
        ]);
    }

    #[DataProvider('badCodes')]
    public function test_refuses_a_code_the_mail_client_would_not_accept(string $code): void
    {
        try {
            OtpToken::header($code, 'https://example.com');
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame(0, $exception->getStatusCode());
            $this->assertSame('validation_error', $exception->getErrorName());
            $this->assertStringContainsString('código do OTP-Token', $exception->getMessage());
        }
    }

    public function test_suggests_the_canonical_origin_when_the_address_has_one(): void
    {
        $this->expectException(PostalException::class);
        $this->expectExceptionMessageMatches('~Use "https://example\.com"\.$~');

        OtpToken::header('123456', 'https://Example.com/login?x=1');
    }

    public function test_no_suggestion_when_no_origin_would_do(): void
    {
        try {
            OtpToken::header('123456', 'http://example.com');
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertStringNotContainsString('Use "', $exception->getMessage());
        }
    }

    public function test_the_header_reaches_the_api_unchanged_in_every_send_method(): void
    {
        $value = OtpToken::header('123456', 'https://example.com');
        $long = OtpToken::header(str_repeat('Ab3', 40), 'https://painel.example.com:8443');
        $transport = FakeTransport::replying(['id' => 'em_1', 'data' => [['id' => 'em_1']]]);
        $postal = $this->client($transport);

        $postal->emails->send([...self::SEND, 'headers' => ['OTP-Token' => $value]]);
        $postal->emails->send([...self::SEND, 'headers' => ['OTP-Token' => $long, 'X-Entity-Ref-ID' => 'a']]);
        $postal->emails->batch([
            [...self::SEND, 'headers' => ['OTP-Token' => $value]],
            [...self::SEND, 'to' => 'bia@exemplo.com', 'headers' => ['OTP-Token' => $long]],
        ]);
        $postal->emails->send([...self::SEND, 'headers' => ['OTP-Token' => $value], 'scheduled_at' => '2030-10-26T12:00:00Z']);
        $postal->emails->send(['to' => 'fulano@exemplo.com', 'template' => ['id' => 'codigo', 'variables' => ['CODIGO' => '123456']], 'headers' => ['OTP-Token' => $value]]);

        [$single, $folded, $batch, $scheduled, $template] = $transport->requests;

        $this->assertSame($value, $single['json']['headers']['OTP-Token']);
        $this->assertSame($long, $folded['json']['headers']['OTP-Token']);
        $this->assertSame([$value, $long], array_map(fn (array $email): string => $email['headers']['OTP-Token'], $batch['json']));
        $this->assertSame($value, $scheduled['json']['headers']['OTP-Token']);
        $this->assertSame($value, $template['json']['headers']['OTP-Token']);
        $this->assertSame('2030-10-26T12:00:00Z', $scheduled['json']['scheduled_at']);
        $this->assertStringContainsString('"OTP-Token":"\\"123456\\"; origin=\\"https://example.com\\""', (string) $single['body']);
        $this->assertStringNotContainsString('=?', (string) $single['body']);
        $this->assertStringNotContainsString('\\u0022', (string) $single['body']);
    }

    public function test_a_422_from_the_api_is_a_postal_exception(): void
    {
        $postal = $this->client(new FakeTransport(fn (): array => FakeTransport::error(422, 'validation_error', 'The `OTP-Token` header must look like ...')));

        try {
            $postal->emails->send([...self::SEND, 'headers' => ['OTP-Token' => '"123456"']]);
            $this->fail('Esperava uma exceção.');
        } catch (PostalException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame('validation_error', $exception->getErrorName());
        }
    }
}
