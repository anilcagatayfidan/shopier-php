<?php

declare(strict_types=1);

namespace Shopier\Resources;

use JsonException;
use Shopier\Config;
use Shopier\DTO\WebhookSubscription;
use Shopier\Exception\ApiException;
use Shopier\Exception\ValidationException;
use Shopier\Http\HttpClientInterface;

final class Webhooks
{
    use ApiResponseHandler;

    public const EVENTS = [
        'order.addressUpdated',
        'order.created',
        'order.fulfilled',
        'product.created',
        'product.updated',
        'refund.requested',
        'refund.updated',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function list(?int $limit = null, ?int $page = null, ?string $sort = null): array
    {
        $query = $this->buildListQuery($limit, $page, $sort);

        $response = $this->httpClient->request(
            'GET',
            $this->config->apiBaseUrl() . 'webhooks' . $query,
            $this->headers(),
            null,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();
        $items = $data['data'] ?? $data['webhooks'] ?? $data;

        if (!is_array($items)) {
            throw new ApiException('Shopier API returned an invalid webhook list response.', $response->statusCode(), $response->body());
        }

        $subscriptions = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $subscriptions[] = WebhookSubscription::fromArray($item);
            }
        }

        return $subscriptions;
    }

    public function create(string $url, string $event): WebhookSubscription
    {
        $this->validateCreate($url, $event);

        try {
            $body = json_encode(['url' => $url, 'event' => $event], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ApiException('Webhook request could not be encoded as JSON: ' . $exception->getMessage(), 0);
        }

        $response = $this->httpClient->request(
            'POST',
            $this->config->apiBaseUrl() . 'webhooks',
            $this->headers(),
            $body,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();

        if ($data === []) {
            throw new ApiException('Shopier API returned an empty or invalid webhook response.', $response->statusCode(), $response->body());
        }

        return WebhookSubscription::fromArray($data);
    }

    public function delete(string|int $id): bool
    {
        $id = trim((string) $id);

        if ($id === '') {
            throw new ValidationException('Webhook id must not be empty.');
        }

        $response = $this->httpClient->request(
            'DELETE',
            $this->config->apiBaseUrl() . 'webhooks/' . rawurlencode($id),
            $this->headers(),
            null,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        return true;
    }

    private function buildListQuery(?int $limit, ?int $page, ?string $sort): string
    {
        $params = [];

        if ($limit !== null) {
            if ($limit < 1 || $limit > 50) {
                throw new ValidationException('Webhook list limit must be between 1 and 50.');
            }

            $params['limit'] = $limit;
        }

        if ($page !== null) {
            if ($page < 1) {
                throw new ValidationException('Webhook list page must be greater than zero.');
            }

            $params['page'] = $page;
        }

        if ($sort !== null) {
            $sort = strtolower($sort);

            if (!in_array($sort, ['asc', 'desc'], true)) {
                throw new ValidationException('Webhook list sort must be "asc" or "desc".');
            }

            $params['sort'] = $sort;
        }

        return $params === [] ? '' : '?' . http_build_query($params);
    }

    private function validateCreate(string $url, string $event): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new ValidationException('Webhook url must be a valid URL.');
        }

        if (!in_array($event, self::EVENTS, true)) {
            throw new ValidationException('Webhook event is not supported.');
        }
    }

    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->config->personalAccessToken(),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => $this->config->userAgent(),
        ];
    }
}
