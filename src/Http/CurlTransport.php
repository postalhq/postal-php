<?php

declare(strict_types=1);

namespace Postal\Http;

use Postal\Exceptions\NetworkException;

/**
 * Transporte HTTP com ext-curl.
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new NetworkException('network_error', 'Não foi possível iniciar o cURL.');
        }

        $responseHeaders = [];
        $lines = [];

        foreach ($headers as $name => $value) {
            $lines[] = $name.': '.$value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ($timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) (min($timeout, 10) * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $separator = strpos($line, ':');

                if ($separator !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
                }

                return strlen($line);
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($handle);

        if ($response === false) {
            $code = curl_errno($handle);
            $message = curl_error($handle);
            curl_close($handle);

            throw $code === CURLE_OPERATION_TIMEDOUT
                ? new NetworkException('timeout', 'O Postal não respondeu no tempo limite.')
                : new NetworkException('network_error', 'Não foi possível falar com o Postal: '.$message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response];
    }
}
