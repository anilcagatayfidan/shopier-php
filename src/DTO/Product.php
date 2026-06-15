<?php

declare(strict_types=1);

namespace Shopier\DTO;

final class Product
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $title,
        public readonly ?string $url,
        public readonly array $data = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $id = $data['id'] ?? $data['productId'] ?? $data['product_id'] ?? '';
        $title = $data['title'] ?? $data['name'] ?? null;
        $url = $data['url'] ?? $data['productUrl'] ?? $data['product_url'] ?? null;

        return new self((string) $id, is_string($title) ? $title : null, is_string($url) ? $url : null, $data);
    }
}
