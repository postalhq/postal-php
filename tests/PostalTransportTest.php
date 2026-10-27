<?php

declare(strict_types=1);

namespace Postal\Tests;

use PHPUnit\Framework\TestCase;
use Postal\Client;
use Postal\Laravel\PostalTransport;
use Postal\OtpToken;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

final class PostalTransportTest extends TestCase
{
    protected function setUp(): void
    {
        if (! class_exists(Mailer::class)) {
            $this->markTestSkipped('symfony/mailer não está instalado.');
        }
    }

    private function transport(FakeTransport $http): PostalTransport
    {
        return new PostalTransport(new Client('ps_test_key', ['transport' => $http, 'retry_delay' => 0]));
    }

    private function mailer(FakeTransport $http): Mailer
    {
        return new Mailer($this->transport($http));
    }

    public function test_sends_html_text_cc_bcc_reply_to_and_custom_headers(): void
    {
        $http = FakeTransport::replying(['id' => 'em_1']);
        $email = (new Email)
            ->from(new Address('voce@seudominio.com.br', 'Minha empresa'))
            ->to('fulano@exemplo.com', new Address('beltrano@exemplo.com', 'Beltrano'))
            ->cc('cc@exemplo.com')
            ->bcc('bcc@exemplo.com')
            ->replyTo('atendimento@seudominio.com.br')
            ->subject('Olá')
            ->html('<p>Oi</p>')
            ->text('Oi');
        $email->getHeaders()->addTextHeader('X-Pedido', '10482')->addTextHeader('List-Unsubscribe', '<https://exemplo.com/sair>');

        $this->mailer($http)->send($email);
        $body = $http->requests[0]['json'];

        $this->assertSame('POST /emails', $http->lines()[0]);
        $this->assertSame('Bearer ps_test_key', $http->requests[0]['headers']['Authorization']);
        $this->assertSame('"Minha empresa" <voce@seudominio.com.br>', $body['from']);
        $this->assertSame(['fulano@exemplo.com', '"Beltrano" <beltrano@exemplo.com>'], $body['to']);
        $this->assertSame(['cc@exemplo.com'], $body['cc']);
        $this->assertSame(['bcc@exemplo.com'], $body['bcc']);
        $this->assertSame(['atendimento@seudominio.com.br'], $body['reply_to']);
        $this->assertSame('Olá', $body['subject']);
        $this->assertSame('<p>Oi</p>', $body['html']);
        $this->assertSame('Oi', $body['text']);
        $this->assertSame(['X-Pedido' => '10482', 'List-Unsubscribe' => '<https://exemplo.com/sair>'], $body['headers']);
        $this->assertArrayNotHasKey('attachments', $body);
    }

    public function test_the_otp_token_header_goes_to_the_api_unchanged(): void
    {
        $value = OtpToken::header(str_repeat('Ab3 ', 25).'x', 'https://painel.exemplo.com:8443');
        $http = FakeTransport::replying(['id' => 'em_1']);
        $email = (new Email)->from('voce@seudominio.com.br')->to('fulano@exemplo.com')->subject('Seu código ✅')->text('Código: 123456');
        $email->getHeaders()->addTextHeader('OTP-Token', $value);

        $this->mailer($http)->send($email);

        $this->assertSame(['OTP-Token' => $value], $http->requests[0]['json']['headers']);
        $this->assertStringNotContainsString('=?', (string) $http->requests[0]['body']);
    }

    public function test_records_the_postal_email_id_on_the_message(): void
    {
        $email = (new Email)->from('voce@seudominio.com.br')->to('fulano@exemplo.com')->subject('S')->text('t');
        $sent = $this->transport(FakeTransport::replying(['id' => 'em_77']))->send($email);

        $this->assertSame('em_77', $sent->getMessageId());
        $this->assertSame('em_77', $sent->getOriginalMessage()->getHeaders()->get('X-Postal-Email-Id')?->getBodyAsString());
    }

    public function test_sends_attachments_and_inline_images(): void
    {
        $http = FakeTransport::replying(['id' => 'em_2']);
        $email = (new Email)
            ->from('voce@seudominio.com.br')->to('fulano@exemplo.com')->subject('Nota')
            ->html('<p>Oi</p>')
            ->attach('conteúdo do pdf', 'nota.pdf', 'application/pdf')
            ->embed('imagem', 'logo.png', 'image/png');

        $this->mailer($http)->send($email);
        $attachments = $http->requests[0]['json']['attachments'];

        $this->assertCount(2, $attachments);
        $this->assertSame(base64_encode('conteúdo do pdf'), $attachments[0]['content']);
        $this->assertSame('nota.pdf', $attachments[0]['filename']);
        $this->assertSame('application/pdf', $attachments[0]['content_type']);
        $this->assertArrayNotHasKey('content_id', $attachments[0]);
        $this->assertSame('logo.png', $attachments[1]['filename']);
        $this->assertNotEmpty($attachments[1]['content_id']);
    }

    public function test_maps_tag_and_metadata_headers_to_tags(): void
    {
        $http = FakeTransport::replying(['id' => 'em_3']);
        $email = (new Email)->from('voce@seudominio.com.br')->to('fulano@exemplo.com')->subject('S')->text('t');
        $email->getHeaders()->add(new TagHeader('boas vindas'));
        $email->getHeaders()->add(new MetadataHeader('pedido_id', '10482'));

        $this->mailer($http)->send($email);
        $body = $http->requests[0]['json'];

        $this->assertSame([['name' => 'tag', 'value' => 'boas_vindas'], ['name' => 'pedido_id', 'value' => '10482']], $body['tags']);
        $this->assertArrayNotHasKey('headers', $body, 'as etiquetas não viram cabeçalhos');
    }

    public function test_envelope_only_recipients_go_as_bcc(): void
    {
        $http = FakeTransport::replying(['id' => 'em_4']);
        $email = (new Email)->from('voce@seudominio.com.br')->to('fulano@exemplo.com')->subject('S')->text('t');

        $this->mailer($http)->send($email, new Envelope(new Address('voce@seudominio.com.br'), [new Address('fulano@exemplo.com'), new Address('oculto@exemplo.com')]));

        $this->assertSame(['fulano@exemplo.com'], $http->requests[0]['json']['to']);
        $this->assertSame(['oculto@exemplo.com'], $http->requests[0]['json']['bcc']);
    }

    public function test_api_errors_become_transport_exceptions_with_the_postal_message(): void
    {
        $http = new FakeTransport(fn (): array => FakeTransport::error(403, 'validation_error', 'The sending domain is not verified.'));
        $email = (new Email)->from('voce@seudominio.com.br')->to('fulano@exemplo.com')->subject('S')->text('t');

        try {
            $this->mailer($http)->send($email);
            $this->fail('Esperava uma exceção.');
        } catch (TransportException $exception) {
            $this->assertStringContainsString('The sending domain is not verified.', $exception->getMessage());
        }
    }

    public function test_to_string_is_the_scheme(): void
    {
        $this->assertSame('postal', (string) new PostalTransport(new Client('ps_x')));
    }
}
