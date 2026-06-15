<?php

declare(strict_types=1);

namespace Shopier;

use Shopier\Exception\ValidationException;

final class Config
{
    public function __construct(
        private readonly string $personalAccessToken,
        private readonly string $apiBaseUrl = 'https://api.shopier.com/v1/',
        private readonly string $frontendBaseUrl = 'https://www.shopier.com',
        private readonly int $timeout = 30,
        private readonly string $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    ) {
        if (trim($personalAccessToken) === '') {
            throw new ValidationException('Personal access token must not be empty.');
        }

        if ($timeout < 1) {
            throw new ValidationException('Timeout must be greater than zero.');
        }
    }

    public function personalAccessToken(): string
    {
        return $this->personalAccessToken;
    }

    public function apiBaseUrl(): string
    {
        return rtrim($this->apiBaseUrl, '/') . '/';
    }

    public function frontendBaseUrl(): string
    {
        return rtrim($this->frontendBaseUrl, '/');
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function userAgent(): string
    {
        return $this->userAgent;
    }
}
