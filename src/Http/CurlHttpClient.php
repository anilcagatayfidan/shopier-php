<?php

declare(strict_types=1);

namespace Shopier\Http;

use Shopier\Exception\ShopierException;

final class CurlHttpClient implements HttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?CookieJar $cookieJar = null,
        ?int $timeout = null
    ): Response {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new ShopierException('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        $normalizedHeaders = $this->normalizeHeaders($headers);

        if ($cookieJar !== null && !$cookieJar->isEmpty()) {
            $normalizedHeaders[] = 'Cookie: ' . $cookieJar->header();
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $timeout ?? 30,
            CURLOPT_TIMEOUT => $timeout ?? 30,
            CURLOPT_HTTPHEADER => $normalizedHeaders,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $line = trim($line);

                if ($line === '' || str_starts_with($line, 'HTTP/')) {
                    return $length;
                }

                if (!str_contains($line, ':')) {
                    return $length;
                }

                [$name, $value] = explode(':', $line, 2);
                $key = strtolower(trim($name));
                $responseHeaders[$key][] = trim($value);

                return $length;
            },
        ]);

        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $rawBody = curl_exec($handle);

        if ($rawBody === false) {
            $error = curl_error($handle);
            curl_close($handle);
            throw new ShopierException($error !== '' ? $error : 'HTTP request failed.');
        }

        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $response = new Response($statusCode, $responseHeaders, (string) $rawBody);

        if ($cookieJar !== null) {
            $cookieJar->addFromResponse($response);
        }

        return $response;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (is_int($name)) {
                $normalized[] = (string) $value;
                continue;
            }

            $normalized[] = $name . ': ' . $value;
        }

        return $normalized;
    }
}
