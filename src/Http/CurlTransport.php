<?php

declare(strict_types=1);

namespace Postal\Http;

use Postal\Exceptions\NetworkException;

/**
 * HTTP transport with ext-curl.
 */
final class CurlTransport implements Transport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout = 5.0): array
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new NetworkException('network_error', 'Could not start cURL.');
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
            CURLOPT_CONNECTTIMEOUT_MS => max(0, (int) ($connectTimeout * 1000)),
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
            // cURL uses the same code for the connection and the whole request running out. If not even the setup phase
            // (DNS, TCP and TLS) finished, the time ran out while connecting.
            $connected = curl_getinfo($handle, CURLINFO_PRETRANSFER_TIME) > 0;
            curl_close($handle);

            if ($code === CURLE_OPERATION_TIMEDOUT && ! $connected) {
                throw new NetworkException('connect_timeout', 'Timed out connecting to Postal (connect_timeout of '.$connectTimeout.' s).');
            }

            throw $code === CURLE_OPERATION_TIMEDOUT
                ? new NetworkException('timeout', 'Postal did not answer within the time limit.')
                : new NetworkException('network_error', 'Could not reach Postal: '.$message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string) $response];
    }
}
