<?php

declare(strict_types=1);

namespace Shopier\Http;

interface HttpClientInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?CookieJar $cookieJar = null,
        ?int $timeout = null
    ): Response;
}
