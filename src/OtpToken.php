<?php

declare(strict_types=1);

namespace Postal;

use Postal\Exceptions\PostalException;

/**
 * Builds the `OTP-Token` header (draft-goto-otp-token), which delivers the code of a verification email in a
 * machine-readable way, bound to an origin, so that the mail client offers it to the page that asked for it:
 * `"123456"; origin="https://example.com"`.
 */
final class OtpToken
{
    private const SHAPE = '/^(https?):\/\/(\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?$/D';

    private const LOOPBACK_HOSTS = ['localhost', '127.0.0.1'];

    /**
     * The value of the `OTP-Token` header, with RFC 9651 escaping (only `\` and `"`).
     *
     * The code must be printable ASCII (from space to `~`) and non-empty. The origin must be in
     * canonical form: `https://host[:port]` in lowercase, no default port, no path and no trailing slash (`http`
     * is only valid for `localhost` and `127.0.0.1`). Anything else throws `PostalException` (`statusCode` 0,
     * `validation_error`), before the API refuses the send with 422.
     *
     * ```php
     * $postal->emails->send([
     *     'from' => 'you@yourdomain.com',
     *     'to' => 'jane@example.com',
     *     'subject' => 'Your code',
     *     'html' => '<p>Your code: 123456</p>',
     *     'headers' => ['OTP-Token' => Postal\OtpToken::header('123456', 'https://example.com')],
     * ]);
     * ```
     *
     * @throws PostalException If the code or the origin will not do.
     */
    public static function header(string $code, string $origin): string
    {
        if ($code === '' || preg_match('/^[\x20-\x7E]+$/D', $code) !== 1) {
            throw self::invalid('The OTP-Token code needs at least one character and only printable ASCII (from space to "~").');
        }

        if (! self::isCanonicalOrigin($origin)) {
            $canonical = self::canonicalOf($origin);

            throw self::invalid(
                'The OTP-Token origin must be the canonical https origin of whoever asks for the code, such as "https://example.com" (lowercase, no path, no trailing slash and no default port).'
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

        // A last numeric label only counts if the whole name is an IPv4 address with four parts.
        return strlen($host) <= 253 && (! ctype_digit($labels[array_key_last($labels)]) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false);
    }

    /**
     * The canonical origin of the given address, if it has one that will do.
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
