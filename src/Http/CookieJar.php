<?php

declare(strict_types=1);

namespace Shopier\Http;

final class CookieJar
{
    private array $cookies = [];

    public function addFromResponse(Response $response): void
    {
        foreach ($response->headerLines('set-cookie') as $header) {
            $this->addFromSetCookieHeader($header);
        }
    }

    public function addFromSetCookieHeader(string $header): void
    {
        $parts = explode(';', $header);
        $cookie = trim($parts[0] ?? '');

        if ($cookie === '' || !str_contains($cookie, '=')) {
            return;
        }

        [$name, $value] = explode('=', $cookie, 2);
        $name = trim($name);

        if ($name === '') {
            return;
        }

        $this->cookies[$name] = $value;
    }

    public function header(): string
    {
        $pairs = [];

        foreach ($this->cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return implode('; ', $pairs);
    }

    public function isEmpty(): bool
    {
        return $this->cookies === [];
    }

    public function all(): array
    {
        return $this->cookies;
    }
}
