<?php

declare(strict_types=1);

namespace Shopier\Resources;

use JsonException;
use Shopier\Config;
use Shopier\DTO\Product;
use Shopier\DTO\ProductCreateRequest;
use Shopier\Exception\ApiException;
use Shopier\Http\HttpClientInterface;

final class Products
{
    use ApiResponseHandler;

    public function __construct(
        private readonly Config $config,
        private readonly HttpClientInterface $httpClient
    ) {
    }

    public function create(ProductCreateRequest $request): Product
    {
        try {
            $body = json_encode($request->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ApiException('Product request could not be encoded as JSON: ' . $exception->getMessage(), 0);
        }

        $response = $this->httpClient->request(
            'POST',
            $this->config->apiBaseUrl() . 'products',
            $this->headers(),
            $body,
            null,
            $this->config->timeout()
        );

        $this->assertSuccessful($response);

        $data = $response->json();

        if ($data === []) {
            throw new ApiException('Shopier API returned an empty or invalid product response.', $response->statusCode(), $response->body());
        }

        return Product::fromArray($data);
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
