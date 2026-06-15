<?php

declare(strict_types=1);

namespace Shopier\Exception;

class ApiException extends ShopierException
{
    public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly string $responseBody = ''
    ) {
        parent::__construct($message, $statusCode);
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
