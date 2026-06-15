<?php

declare(strict_types=1);

namespace Shopier\Exception;

/**
 * Thrown when the storefront returns a Cloudflare/JS bot challenge
 * (e.g. "Just a moment..."). This cannot be cleared with HTTP headers alone;
 * the caller should retry from a cleaner IP or use a TLS-impersonation client.
 */
class CloudflareChallengeException extends CheckoutFlowException
{
    public function __construct(
        string $message,
        private readonly int $statusCode = 0,
        private readonly string $responseBody = ''
    ) {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getResponseBody(): string
    {
        return $this->responseBody;
    }
}
