<?php

declare(strict_types=1);

namespace Postal\Http;

use Postal\Exceptions\NetworkException;

/**
 * Faz uma requisição HTTP. O padrão é {@see CurlTransport}; troque por outra implementação nos testes.
 */
interface Transport
{
    /**
     * @param  array<string, string>  $headers
     * @param  float  $timeout  Segundos para a requisição inteira (conexão, envio e resposta).
     * @param  float  $connectTimeout  Segundos só para abrir a conexão (DNS, TCP e TLS); 0 desliga o limite próprio.
     * @return array{status: int, headers: array<string, string>, body: string} Os nomes dos cabeçalhos em minúsculas.
     *
     * @throws NetworkException Quando a API não respondeu (`network_error`, `timeout` ou `connect_timeout`).
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout = 5.0): array;
}
