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
     * @return array{status: int, headers: array<string, string>, body: string} Os nomes dos cabeçalhos em minúsculas.
     *
     * @throws NetworkException Quando a API não respondeu.
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array;
}
