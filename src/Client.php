<?php

declare(strict_types=1);

namespace Postal;

use Postal\Exceptions\NetworkException;
use Postal\Exceptions\PostalException;
use Postal\Http\CurlTransport;
use Postal\Http\Transport;
use Postal\Resources\Contacts;
use Postal\Resources\Domains;
use Postal\Resources\Emails;
use Postal\Resources\Events;
use Postal\Resources\Segments;
use Postal\Resources\Topics;
use Postal\Resources\Webhooks;

/**
 * Cliente da API do Postal.
 *
 * Os campos de entrada e de saída têm os mesmos nomes da API (snake_case), então a documentação em
 * https://postal.so/docs/api vale direto para o SDK. As respostas são arrays.
 *
 * ```php
 * $postal = new Postal\Client(getenv('POSTAL_API_KEY'));
 *
 * $postal->emails->send([
 *     'from' => 'Minha empresa <voce@seudominio.com.br>',
 *     'to' => 'fulano@exemplo.com',
 *     'subject' => 'Olá',
 *     'html' => '<p>Bem-vindo!</p>',
 * ]);
 * ```
 */
final class Client
{
    public const VERSION = '0.1.0';

    public const DEFAULT_BASE_URL = 'https://api.postal.so';

    /**
     * Respostas 429 que repetir não resolve: a cota do plano acabou.
     */
    private const NON_RETRYABLE_429 = ['daily_quota_exceeded', 'monthly_quota_exceeded', 'ai_credits_exhausted'];

    private const MAX_RETRY_DELAY_MS = 30_000;

    public readonly Emails $emails;

    public readonly Contacts $contacts;

    public readonly Events $events;

    public readonly Segments $segments;

    public readonly Topics $topics;

    public readonly Domains $domains;

    public readonly Webhooks $webhooks;

    private readonly string $apiKey;

    private readonly string $baseUrl;

    private readonly int $maxRetries;

    private readonly float $timeout;

    private readonly float $connectTimeout;

    private readonly int $retryDelayMs;

    private readonly Transport $transport;

    private readonly string $userAgent;

    private ?RateLimit $rateLimit = null;

    /**
     * @param  string|null  $apiKey  Chave de API (`ps_...`). Sem chave, usa a variável de ambiente `POSTAL_API_KEY`.
     * @param  array{base_url?: string, max_retries?: int, timeout?: float|int, connect_timeout?: float|int, retry_delay?: int, transport?: Transport, user_agent?: string}  $options
     *                                                                                                                                                                                 Opções: `max_retries` são as tentativas extras em 429 e 5xx (padrão 2); `timeout`, os segundos por
     *                                                                                                                                                                                 tentativa, do início ao fim (padrão 30); `connect_timeout`, os segundos só para abrir a conexão
     *                                                                                                                                                                                 (padrão 5; 0 desliga); `retry_delay`, a espera base em milissegundos, que dobra a cada tentativa
     *                                                                                                                                                                                 (padrão 500). Com a API fora do ar, o pior caso é cerca de
     *                                                                                                                                                                                 `(max_retries + 1) × connect_timeout` mais as esperas entre tentativas.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $apiKey ??= (getenv('POSTAL_API_KEY') ?: null);

        if ($apiKey === null || $apiKey === '') {
            throw new PostalException(0, 'missing_api_key', 'Informe a chave de API: new Client("ps_...") ou a variável de ambiente POSTAL_API_KEY.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($options['base_url'] ?? (getenv('POSTAL_API_URL') ?: self::DEFAULT_BASE_URL), '/');
        $this->maxRetries = max(0, $options['max_retries'] ?? 2);
        $this->timeout = (float) ($options['timeout'] ?? 30);
        $this->connectTimeout = max(0.0, (float) ($options['connect_timeout'] ?? 5));
        $this->retryDelayMs = max(0, $options['retry_delay'] ?? 500);
        $this->transport = $options['transport'] ?? new CurlTransport;
        $this->userAgent = 'postal-so-php/'.self::VERSION.(isset($options['user_agent']) ? ' '.$options['user_agent'] : '');

        $this->emails = new Emails($this);
        $this->contacts = new Contacts($this);
        $this->events = new Events($this);
        $this->segments = new Segments($this);
        $this->topics = new Topics($this);
        $this->domains = new Domains($this);
        $this->webhooks = new Webhooks($this);
    }

    /**
     * O limite de requisições dos cabeçalhos da última resposta (`X-RateLimit-Limit`, `X-RateLimit-Remaining` e,
     * na 429, `Retry-After` e `X-RateLimit-Reset`), inclusive de respostas de erro.
     *
     * Funciona com chave só de envio e não faz requisição extra (diferente de {@see usage()}). Devolve `null`
     * até chegar uma resposta com esses cabeçalhos; uma resposta sem eles mantém o último valor conhecido.
     */
    public function rateLimit(): ?RateLimit
    {
        return $this->rateLimit;
    }

    /**
     * Plano, cotas e limite de requisições da conta (`GET /usage`). Exige chave de acesso total.
     *
     * @return array<string, mixed>
     */
    public function usage(): array
    {
        return $this->request('GET', '/usage');
    }

    /**
     * Faz uma requisição e devolve o JSON decodificado. Usado pelos recursos.
     *
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body
     * @param  array{idempotency_key?: string|null, auto_idempotency?: bool, idempotent?: bool}  $options
     * @return array<string, mixed>
     *
     * @throws PostalException
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null, array $options = []): array
    {
        $response = $this->send($method, $path, $query, $body, $options);

        if ($response['body'] === '') {
            return [];
        }

        $decoded = json_decode($response['body'], true);

        if (! is_array($decoded)) {
            throw new PostalException($response['status'], 'invalid_response', 'A resposta do Postal não é um JSON válido.');
        }

        return $decoded;
    }

    /**
     * Devolve o corpo como texto (para `GET /emails/{id}/raw`).
     */
    public function requestText(string $method, string $path): string
    {
        return $this->send($method, $path, [], null, [])['body'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<mixed>|null  $body
     * @param  array{idempotency_key?: string|null, auto_idempotency?: bool, idempotent?: bool}  $options
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $path, array $query, ?array $body, array $options): array
    {
        $idempotencyKey = $options['idempotency_key'] ?? (($options['auto_idempotency'] ?? false) ? self::uuid() : null);
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => $this->userAgent,
        ];

        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        }

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        // Repetir um POST sem chave de idempotência após um 5xx ou uma queda de rede poderia duplicar o efeito.
        $retriesServerErrors = in_array($method, ['GET', 'DELETE'], true) || ($options['idempotent'] ?? false) || $idempotencyKey !== null;
        $url = $this->baseUrl.$path.$this->queryString($query);
        $payload = $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        for ($attempt = 0; ; $attempt++) {
            $canRetry = $attempt < $this->maxRetries;

            try {
                $response = $this->transport->send($method, $url, $headers, $payload, $this->timeout, $this->connectTimeout);
            } catch (NetworkException $exception) {
                if ($canRetry && $retriesServerErrors) {
                    $this->sleep($this->delay($attempt));

                    continue;
                }

                throw $exception;
            }

            $this->rateLimit = RateLimit::fromHeaders($response['headers']) ?? $this->rateLimit;

            if ($response['status'] >= 200 && $response['status'] < 300) {
                return $response;
            }

            $error = $this->toException($response);
            $retryable = ($response['status'] === 429 && ! in_array($error->errorName, self::NON_RETRYABLE_429, true))
                || ($retriesServerErrors && ($response['status'] >= 500 || $error->errorName === 'concurrent_idempotent_requests'));

            if ($canRetry && $retryable) {
                $this->sleep($error->retryAfter !== null ? min($error->retryAfter * 1000, self::MAX_RETRY_DELAY_MS) : $this->delay($attempt));

                continue;
            }

            throw $error;
        }
    }

    /**
     * @param  array{status: int, headers: array<string, string>, body: string}  $response
     */
    private function toException(array $response): PostalException
    {
        $retryAfterHeader = $response['headers']['retry-after'] ?? null;
        $retryAfter = $retryAfterHeader !== null && ctype_digit($retryAfterHeader) ? (int) $retryAfterHeader : null;
        $decoded = json_decode($response['body'], true);
        $body = is_array($decoded) && is_string($decoded['name'] ?? null) ? $decoded : null;

        return new PostalException(
            $response['status'],
            $body['name'] ?? ($response['status'] >= 500 ? 'internal_server_error' : 'unexpected_error'),
            is_string($body['message'] ?? null) ? $body['message'] : 'Erro '.$response['status'].' do Postal.',
            $retryAfter,
            $body,
            RateLimit::fromHeaders($response['headers']),
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function queryString(array $query): string
    {
        $pairs = [];

        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }

            $pairs[$key] = match (true) {
                is_array($value) => implode(',', $value),
                is_bool($value) => $value ? 'true' : 'false',
                default => (string) $value,
            };
        }

        return $pairs === [] ? '' : '?'.http_build_query($pairs, '', '&', PHP_QUERY_RFC3986);
    }

    private function delay(int $attempt): int
    {
        $base = $this->retryDelayMs * (2 ** $attempt);

        return (int) min($base + random_int(0, (int) ($base * 0.25)), self::MAX_RETRY_DELAY_MS);
    }

    private function sleep(int $milliseconds): void
    {
        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
