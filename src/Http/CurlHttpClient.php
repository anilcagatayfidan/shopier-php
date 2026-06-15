<?php

declare(strict_types=1);

namespace Shopier\Http;

use Shopier\Exception\ShopierException;

final class CurlHttpClient implements HttpClientInterface
{
    private const MAX_REDIRECTS = 5;

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?CookieJar $cookieJar = null,
        ?int $timeout = null
    ): Response {
        $method = strtoupper($method);
        $redirects = 0;

        while (true) {
            $response = $this->executeSingle($method, $url, $headers, $body, $cookieJar, $timeout);

            if ($cookieJar !== null) {
                $cookieJar->addFromResponse($response);
            }

            $location = $response->header('location');

            if (!$this->isRedirect($response->statusCode()) || $location === null || $redirects >= self::MAX_REDIRECTS) {
                return $response;
            }

            $url = $this->resolveLocation($url, $location);
            $redirects++;

            // Browsers turn the redirected navigation into a GET and drop the body
            // (303 always, and in practice for 301/302 after a POST as well).
            if ($method !== 'HEAD') {
                $method = 'GET';
            }

            $body = null;
            $headers = $this->stripBodyHeaders($headers);
        }
    }

    private function executeSingle(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        ?CookieJar $cookieJar,
        ?int $timeout
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
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            // Redirects are followed manually (see request()) so the cookie jar is
            // updated and re-applied on every hop; cURL's own follow would not
            // re-send cookies captured from intermediate responses.
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

        return new Response($statusCode, $responseHeaders, (string) $rawBody);
    }

    private function isRedirect(int $statusCode): bool
    {
        return in_array($statusCode, [301, 302, 303, 307, 308], true);
    }

    private function resolveLocation(string $currentUrl, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($currentUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $base = substr($path, 0, strrpos($path, '/') + 1);

        return $origin . $base . $location;
    }

    /**
     * @param array<int|string, string> $headers
     * @return array<int|string, string>
     */
    private function stripBodyHeaders(array $headers): array
    {
        foreach ($headers as $name => $value) {
            if (is_string($name) && strtolower($name) === 'content-type') {
                unset($headers[$name]);
            }
        }

        return $headers;
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
