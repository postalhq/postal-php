<?php

declare(strict_types=1);

namespace Postal\Tests;

use PHPUnit\Framework\TestCase;
use Postal\Exceptions\WebhookVerificationException;
use Postal\Webhook;

final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_c2VncmVkby1kZS1leGVtcGxvLTEyMzQ1Ng==';

    private const BODY = '{"type":"email.delivered","created_at":"2026-10-24T13:02:14.120Z","data":{"email_id":"abc"}}';

    private const ID = 'msg_123';

    private const TIMESTAMP = 1761321600;

    /**
     * Calculada pelo backend (App\Services\Webhooks\WebhookSigner::sign) para o corpo acima.
     */
    private const BACKEND_SIGNATURE = 'DwAjFL7bHXusfCFHdLLHtcXFwxa+x4E+CzWtO6oF8Tk=';

    private static function sign(string $secret, string $id, int $timestamp, string $body): string
    {
        return base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", base64_decode(preg_replace('/^whsec_/', '', $secret)), true));
    }

    /**
     * @return array<string, string>
     */
    private static function headers(?string $signature = null, array $extra = []): array
    {
        return [
            'webhook-id' => self::ID,
            'webhook-timestamp' => (string) self::TIMESTAMP,
            'webhook-signature' => $signature ?? 'v1,'.self::BACKEND_SIGNATURE,
            ...$extra,
        ];
    }

    public function test_the_reference_signature_matches_the_scheme(): void
    {
        $this->assertSame(self::BACKEND_SIGNATURE, self::sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY));
    }

    public function test_accepts_a_valid_delivery_and_returns_the_event(): void
    {
        $event = Webhook::verify(self::BODY, self::headers(), self::SECRET, now: self::TIMESTAMP + 10);

        $this->assertSame('email.delivered', $event['type']);
        $this->assertSame('abc', $event['data']['email_id']);
    }

    public function test_accepts_svix_headers_any_case_and_array_values(): void
    {
        $svix = ['svix-id' => self::ID, 'svix-timestamp' => (string) self::TIMESTAMP, 'svix-signature' => 'v1,'.self::BACKEND_SIGNATURE];
        $symfony = ['Webhook-Id' => [self::ID], 'Webhook-Timestamp' => [(string) self::TIMESTAMP], 'Webhook-Signature' => ['v1,'.self::BACKEND_SIGNATURE]];

        $this->assertSame('email.delivered', Webhook::verify(self::BODY, $svix, self::SECRET, now: self::TIMESTAMP)['type']);
        $this->assertSame('email.delivered', Webhook::verify(self::BODY, $symfony, self::SECRET, now: self::TIMESTAMP)['type']);
    }

    public function test_accepts_several_signatures_if_one_matches(): void
    {
        $signature = 'v1,'.self::sign('whsec_b3V0cm8=', self::ID, self::TIMESTAMP, self::BODY).' v1,'.self::BACKEND_SIGNATURE;

        $this->assertSame('email.delivered', Webhook::verify(self::BODY, self::headers($signature), self::SECRET, now: self::TIMESTAMP)['type']);
    }

    public function test_rejects_a_changed_body_and_a_wrong_secret(): void
    {
        $this->expectException(WebhookVerificationException::class);

        Webhook::verify(self::BODY.' ', self::headers(), self::SECRET, now: self::TIMESTAMP);
    }

    public function test_rejects_the_wrong_secret(): void
    {
        try {
            Webhook::verify(self::BODY, self::headers(), 'whsec_b3V0cm8=', now: self::TIMESTAMP);
            $this->fail('Esperava uma exceção.');
        } catch (WebhookVerificationException $exception) {
            $this->assertSame('webhook_verification_failed', $exception->getErrorName());
        }
    }

    public function test_rejects_timestamps_outside_the_tolerance_in_both_directions(): void
    {
        foreach ([self::TIMESTAMP + 301, self::TIMESTAMP - 301] as $now) {
            try {
                Webhook::verify(self::BODY, self::headers(), self::SECRET, now: $now);
                $this->fail('Esperava uma exceção.');
            } catch (WebhookVerificationException $exception) {
                $this->assertStringContainsString('tolerância', $exception->getMessage());
            }
        }

        $this->assertIsArray(Webhook::verify(self::BODY, self::headers(), self::SECRET, now: self::TIMESTAMP + 300));
        $this->assertIsArray(Webhook::verify(self::BODY, self::headers(), self::SECRET, tolerance: 10000, now: self::TIMESTAMP + 5000));
    }

    public function test_rejects_missing_headers_unknown_versions_and_malformed_signatures(): void
    {
        $cases = [
            [[], 'Faltam'],
            [self::headers('v2,'.self::BACKEND_SIGNATURE), 'não confere'],
            [self::headers('v1,abc'), 'não confere'],
            [self::headers('v1'), 'não confere'],
            [self::headers(null, ['webhook-timestamp' => 'ontem']), 'inválido'],
        ];

        foreach ($cases as [$headers, $fragment]) {
            try {
                Webhook::verify(self::BODY, $headers, self::SECRET, now: self::TIMESTAMP);
                $this->fail('Esperava uma exceção para '.$fragment);
            } catch (WebhookVerificationException $exception) {
                $this->assertStringContainsString($fragment, $exception->getMessage());
            }
        }
    }

    public function test_uses_the_real_clock_by_default(): void
    {
        $timestamp = time();
        $headers = ['webhook-id' => self::ID, 'webhook-timestamp' => (string) $timestamp, 'webhook-signature' => 'v1,'.self::sign(self::SECRET, self::ID, $timestamp, self::BODY)];

        $this->assertSame('email.delivered', Webhook::verify(self::BODY, $headers, self::SECRET)['type']);
    }
}
