<?php

declare(strict_types=1);

namespace Postal;

use Postal\Exceptions\PostalException;

/**
 * Monta o cabeçalho `OTP-Token` (draft-goto-otp-token), que entrega o código de um e-mail de verificação de
 * forma legível por máquina e presa a uma origem, para que o cliente de e-mail o ofereça à página que o pediu:
 * `"123456"; origin="https://exemplo.com"`.
 */
final class OtpToken
{
    private const SHAPE = '/^(https?):\/\/(\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?$/D';

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * O valor do cabeçalho `OTP-Token`, com o escape do RFC 9651 (só `\` e `"`).
     *
     * O código precisa ser ASCII imprimível (de espaço a `~`) e não vazio. A origem precisa estar na forma
     * canônica: `https://host[:porta]` em minúsculas, sem porta padrão, sem caminho nem barra no fim (`http`
     * só vale para `localhost` e `127.0.0.1`). Qualquer outra coisa lança `PostalException` (`statusCode` 0,
     * `validation_error`), antes de a API recusar o envio com 422.
     *
     * ```php
     * $postal->emails->send([
     *     'from' => 'voce@seudominio.com.br',
     *     'to' => 'fulano@exemplo.com',
     *     'subject' => 'Seu código',
     *     'html' => '<p>Seu código: 123456</p>',
     *     'headers' => ['OTP-Token' => Postal\OtpToken::header('123456', 'https://exemplo.com')],
     * ]);
     * ```
     *
     * @throws PostalException Se o código ou a origem não servirem.
     */
    public static function header(string $code, string $origin): string
    {
        if ($code === '' || preg_match('/^[\x20-\x7E]+$/D', $code) !== 1) {
            throw self::invalid('O código do OTP-Token precisa ter ao menos um caractere e só ASCII imprimível (de espaço a "~").');
        }

        if (! self::isCanonicalOrigin($origin)) {
            $canonical = self::canonicalOf($origin);

            throw self::invalid(
                'A origem do OTP-Token precisa ser a origem https canônica de quem pede o código, como "https://exemplo.com" (minúsculas, sem caminho, sem barra no fim e sem porta padrão).'
                .($canonical === null ? '' : " Use \"{$canonical}\".")
            );
        }

        return self::quote($code).'; origin='.self::quote($origin);
    }

    private static function quote(string $text): string
    {
        return '"'.addcslashes($text, '\\"').'"';
    }

    private static function invalid(string $message): PostalException
    {
        return new PostalException(0, 'validation_error', $message);
    }

    private static function isCanonicalOrigin(string $origin): bool
    {
        if (preg_match(self::SHAPE, $origin, $match) !== 1) {
            return false;
        }

        [, $scheme, $host] = $match;
        $port = isset($match[3]) ? (int) $match[3] : null;

        if ($port !== null && ($port > 65535 || $port === ($scheme === 'https' ? 443 : 80))) {
            return false;
        }

        if ($scheme === 'http' && ! in_array($host, self::LOOPBACK_HOSTS, true)) {
            return false;
        }

        if (str_starts_with($host, '[')) {
            $address = substr($host, 1, -1);

            return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false && inet_ntop((string) inet_pton($address)) === $address;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)$/D', $label) !== 1) {
                return false;
            }
        }

        // Um último rótulo numérico só vale se o nome todo for um endereço IPv4 com quatro partes.
        return strlen($host) <= 253 && (! ctype_digit($labels[array_key_last($labels)]) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
    }

    /**
     * A origem canônica do endereço dado, se ele tiver uma que sirva.
     */
    private static function canonicalOf(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && $parts['port'] !== ($scheme === 'https' ? 443 : 80) ? ':'.$parts['port'] : '';
        $origin = $scheme.'://'.strtolower($parts['host']).$port;

        return self::isCanonicalOrigin($origin) ? $origin : null;
    }
}
