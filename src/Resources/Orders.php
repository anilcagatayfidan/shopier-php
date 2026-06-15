<?php

declare(strict_types=1);

namespace Shopier\Resources;

use JsonException;
use Shopier\Config;
use Shopier\DTO\Order;
use Shopier\Exception\ApiException;
use Shopier\Exception\ValidationException;
use Shopier\Http\HttpClientInterface;

final class Orders
{
    use ApiResponseHandler;

    public const FULFILLMENT_STATUSES = ['unfulfilled', 'fulfilled'];
    public const REFUND_TYPES = ['none', 'partial', 'full'];
    public const SORTS = ['dateAsc', 'dateDesc'];

    private const ALLOWED_FILTERS = [
        'dateStart',
        'dateEnd',
        'fulfillmentStatus',
        'refundType',
        'customerEmail',
        'customerPhone',
        'productId',
        'limit',
        'page',
        'sort',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    /**
     * List orders. Supported $filters keys: dateStart, dateEnd, fulfillmentStatus,
     * refundType, customerEmail, customerPhone, productId, limit, page, sort.
     *
     * @return Order[]
     */
    public function list(array $filters = []): array
    {
        $query = $this->buildListQuery($filters);

        $response = $this->httpClient->request(
            'GET',
            $this->config->apiBaseUrl() . 'orders' . $query,
            $this->headers(),
            null,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();
        $items = $data['data'] ?? $data['orders'] ?? $data;

        if (!is_array($items)) {
            throw new ApiException('Shopier API returned an invalid order list response.', $response->statusCode(), $response->body());
        }

        $orders = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                $orders[] = Order::fromArray($item);
            }
        }

        return $orders;
    }

    public function get(string|int $id): Order
    {
        $id = $this->normalizeId($id);

        $response = $this->httpClient->request(
            'GET',
            $this->config->apiBaseUrl() . 'orders/' . rawurlencode($id),
            $this->headers(),
            null,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();

        if ($data === []) {
            throw new ApiException('Shopier API returned an empty or invalid order response.', $response->statusCode(), $response->body());
        }

        return Order::fromArray($data);
    }

    /**
     * Update an order. Used for closing the order (fulfillments) and/or changing
     * the shipping address (shippingInfo).
     *
     * @param array<string, mixed> $payload
     */
    public function update(string|int $id, array $payload): Order
    {
        $id = $this->normalizeId($id);

        if ($payload === []) {
            throw new ValidationException('Order update payload must not be empty.');
        }

        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ApiException('Order update request could not be encoded as JSON: ' . $exception->getMessage(), 0);
        }

        $response = $this->httpClient->request(
            'PUT',
            $this->config->apiBaseUrl() . 'orders/' . rawurlencode($id),
            $this->headers(),
            $body,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();

        if ($data === []) {
            throw new ApiException('Shopier API returned an empty or invalid order response.', $response->statusCode(), $response->body());
        }

        return Order::fromArray($data);
    }

    /**
     * Collect additional financial information (e.g. installments) about a single order.
     *
     * @return array<string, mixed>
     */
    public function transaction(string|int $orderId): array
    {
        $orderId = $this->normalizeId($orderId);

        $response = $this->httpClient->request(
            'GET',
            $this->config->apiBaseUrl() . 'orders/transactions/' . rawurlencode($orderId),
            $this->headers(),
            null,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();
        $transaction = $data['data'] ?? $data;

        if (!is_array($transaction)) {
            throw new ApiException('Shopier API returned an invalid order transaction response.', $response->statusCode(), $response->body());
        }

        return $transaction;
    }

    private function buildListQuery(array $filters): string
    {
        $params = [];

        foreach ($filters as $key => $value) {
            if (!in_array($key, self::ALLOWED_FILTERS, true)) {
                throw new ValidationException(sprintf('Unsupported order list filter "%s".', $key));
            }

            if ($value === null || $value === '') {
                continue;
            }

            $params[$key] = $value;
        }

        if (isset($params['fulfillmentStatus']) && !in_array($params['fulfillmentStatus'], self::FULFILLMENT_STATUSES, true)) {
            throw new ValidationException('Order list fulfillmentStatus must be "unfulfilled" or "fulfilled".');
        }

        if (isset($params['refundType']) && !in_array($params['refundType'], self::REFUND_TYPES, true)) {
            throw new ValidationException('Order list refundType must be one of "none", "partial", "full".');
        }

        if (isset($params['sort']) && !in_array($params['sort'], self::SORTS, true)) {
            throw new ValidationException('Order list sort must be "dateAsc" or "dateDesc".');
        }

        if (isset($params['limit'])) {
            $limit = (int) $params['limit'];

            if ($limit < 1 || $limit > 50) {
                throw new ValidationException('Order list limit must be between 1 and 50.');
            }

            $params['limit'] = $limit;
        }

        if (isset($params['page'])) {
            $page = (int) $params['page'];

            if ($page < 1) {
                throw new ValidationException('Order list page must be greater than zero.');
            }

            $params['page'] = $page;
        }

        return $params === [] ? '' : '?' . http_build_query($params);
    }

    private function normalizeId(string|int $id): string
    {
        $id = trim((string) $id);

        if ($id === '') {
            throw new ValidationException('Order id must not be empty.');
        }

        return $id;
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
