<?php

declare(strict_types=1);

namespace Shopier\Resources;

use Shopier\Exception\ApiException;
use Shopier\Exception\AuthenticationException;
use Shopier\Exception\RateLimitException;
use Shopier\Http\Response;

trait ApiResponseHandler
{
    private function assertSuccessful(Response $response): void
    {
        if ($response->isSuccessful()) {
            return;
        }

        $message = $this->apiErrorMessage($response);

        if (in_array($response->statusCode(), [401, 403], true)) {
            throw new AuthenticationException($message, $response->statusCode(), $response->body());
        }

        if ($response->statusCode() === 429) {
            throw new RateLimitException($message, $response->statusCode(), $response->body());
        }

        throw new ApiException($message, $response->statusCode(), $response->body());
    }

    private function apiErrorMessage(Response $response): string
    {
        $json = $response->json();
        $message = $json['message'] ?? $json['error'] ?? $json['detail'] ?? null;

        if (is_array($message)) {
            $message = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($message) && trim($message) !== '') {
            return 'Shopier API request failed with status ' . $response->statusCode() . ': ' . $message;
        }

        $body = trim($response->body());

        if ($body !== '') {
            return 'Shopier API request failed with status ' . $response->statusCode() . ': ' . substr($body, 0, 500);
        }

        return 'Shopier API request failed with status ' . $response->statusCode() . '.';
    }
}
