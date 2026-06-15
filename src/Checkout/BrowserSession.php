<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\Config;
use Shopier\Http\CookieJar;
use Shopier\Http\HttpClientInterface;
use Shopier\Http\Response;

final class BrowserSession
{
    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient,
        private readonly CookieJar $cookieJar = new CookieJar()
    ) {
    }

    public function get(string $url, array $headers = []): Response
    {
        return $this->httpClient->request(
            'GET',
            $this->resolveUrl($url),
            $this->headers($headers),
            null,
            $this->cookieJar,
            $this->config->timeout()
        );
    }

    public function postForm(string $url, array $data, array $headers = []): Response
    {
        return $this->httpClient->request(
            'POST',
            $this->resolveUrl($url),
            $this->headers(array_replace([
                'Content-Type' => 'application/x-www-form-urlencoded',
            ], $headers)),
            http_build_query($data, '', '&', PHP_QUERY_RFC1738),
            $this->cookieJar,
            $this->config->timeout()
        );
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

    private function headers(array $headers): array
    {
        return array_replace([
            'Accept' => 'text/html,application/json,*/*',
            'User-Agent' => $this->config->userAgent(),
        ], $headers);
    }
}
