<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\Config;
use Shopier\Http\CookieJar;
use Shopier\Http\HttpClientInterface;
use Shopier\Http\Response;

final class BrowserSession
{
    private ?string $referer = null;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient,
        private readonly CookieJar $cookieJar = new CookieJar()
    ) {
    }

    public function get(string $url, array $headers = []): Response
    {
        $resolved = $this->resolveUrl($url);

        $response = $this->httpClient->request(
            'GET',
            $resolved,
            $this->headers($this->navigationHeaders(), $headers),
            null,
            $this->cookieJar,
            $this->config->timeout()
        );

        $this->referer = $resolved;

        return $response;
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
