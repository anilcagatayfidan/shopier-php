<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\Config;
use Shopier\Exception\TimeoutException;
use Shopier\Http\CookieJar;
use Shopier\Http\HttpClientInterface;
use Shopier\Http\Response;

final class BrowserSession
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    private ?string $referer = null;

    /**
     * @param int $maxRetries How many extra attempts to make for GET requests that
     *                        hit a timeout or a transient 429/5xx. POSTs are never
     *                        auto-retried (they may create an order server-side).
     */
    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient,
        private readonly CookieJar $cookieJar = new CookieJar(),
        private readonly int $maxRetries = 2
    ) {
    }

    public function get(string $url, array $headers = []): Response
    {
        $resolved = $this->resolveUrl($url);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->request(
                    'GET',
                    $resolved,
                    $this->headers($this->navigationHeaders(), $headers),
                    null,
                    $this->cookieJar,
                    $this->config->timeout()
                );
            } catch (TimeoutException $exception) {
                // Idempotent GET — safe to retry on timeout.
                if ($attempt >= $this->maxRetries) {
                    throw $exception;
                }

                $this->sleepBeforeRetry($attempt);
                $attempt++;
                continue;
            }

            if ($this->isRetryable($response->statusCode()) && $attempt < $this->maxRetries) {
                $this->sleepBeforeRetry($attempt);
                $attempt++;
                continue;
            }

            $this->referer = $resolved;

            return $response;
        }
    }

    private function isRetryable(int $statusCode): bool
    {
        return in_array($statusCode, self::RETRYABLE_STATUSES, true);
    }

    private function sleepBeforeRetry(int $attempt): void
    {
        // 0.5s, 1s, 2s, ... (capped)
        $delayMs = min(500 * (2 ** $attempt), 4000);
        usleep($delayMs * 1000);
    }

    public function postForm(string $url, array $data, array $headers = []): Response
    {
        $resolved = $this->resolveUrl($url);

        $response = $this->httpClient->request(
            'POST',
            $resolved,
            $this->headers($this->fetchHeaders(), array_replace([
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], $headers)),
            http_build_query($data, '', '&', PHP_QUERY_RFC1738),
            $this->cookieJar,
            $this->config->timeout()
        );

        $this->referer = $resolved;

        return $response;
    }

    /**
     * POST that submits a form as a top-level navigation (returns an HTML page),
     * e.g. the payment page form submission. Unlike postForm() this sends
     * document/navigate headers and no X-Requested-With.
     */
    public function postFormNavigate(string $url, array $data, array $headers = []): Response
    {
        $resolved = $this->resolveUrl($url);

        $response = $this->httpClient->request(
            'POST',
            $resolved,
            $this->headers($this->navigationPostHeaders(), array_replace([
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], $headers)),
            http_build_query($data, '', '&', PHP_QUERY_RFC1738),
            $this->cookieJar,
            $this->config->timeout()
        );

        $this->referer = $resolved;

        return $response;
    }

    public function cookieJar(): CookieJar
    {
        return $this->cookieJar;
    }

    private function resolveUrl(string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        return $this->config->frontendBaseUrl() . '/' . ltrim($url, '/');
    }

    /**
     * Top-level document navigation (a GET that loads an HTML page).
     *
     * @return array<string, string>
     */
    private function navigationHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Site' => $this->referer === null ? 'none' : 'same-origin',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-User' => '?1',
            'Sec-Fetch-Dest' => 'document',
        ];
    }

    /**
     * Top-level form submission that navigates to a new HTML page (e.g. the
     * payment page). Like a navigation GET but with an Origin header.
     *
     * @return array<string, string>
     */
    private function navigationPostHeaders(): array
    {
        return [
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
            'Origin' => $this->config->frontendBaseUrl(),
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-User' => '?1',
            'Sec-Fetch-Dest' => 'document',
        ];
    }

    /**
     * In-page API call (the XHR/fetch requests the storefront JS makes).
     *
     * @return array<string, string>
     */
    private function fetchHeaders(): array
    {
        return [
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Accept-Language' => 'tr-TR,tr;q=0.9,en;q=0.8',
            'X-Requested-With' => 'XMLHttpRequest',
            'Origin' => $this->config->frontendBaseUrl(),
            'Sec-Fetch-Site' => 'same-origin',
            'Sec-Fetch-Mode' => 'cors',
            'Sec-Fetch-Dest' => 'empty',
        ];
    }

    /**
     * @param array<string, string> $base
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function headers(array $base, array $overrides): array
    {
        $headers = array_replace($base, [
            'User-Agent' => $this->config->userAgent(),
        ]);

        if ($this->referer !== null) {
            $headers['Referer'] = $this->referer;
        }

        return array_replace($headers, $overrides);
    }
}
