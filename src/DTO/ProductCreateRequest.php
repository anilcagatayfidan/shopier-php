<?php

declare(strict_types=1);

namespace Shopier\DTO;

use Shopier\Exception\ValidationException;

final class ProductCreateRequest
{
    public function __construct(
        public readonly string $title,
        public readonly array $media,
        public readonly string $currency,
        public readonly int|float|string $price,
        public readonly string $shippingPayer = 'sellerPays',
        public readonly string $type = 'digital',
        public readonly array $extra = []
    ) {
    }

    public function toArray(): array
    {
        $this->validate();

        return array_replace_recursive($this->extra, [
            'title' => $this->title,
            'type' => $this->type,
            'media' => $this->media,
            'priceData' => [
                'currency' => $this->currency,
                'price' => $this->price,
            ],
            'shippingPayer' => $this->shippingPayer,
        ]);
    }

    public function validate(): void
    {
        if (trim($this->title) === '') {
            throw new ValidationException('Product title must not be empty.');
        }

        if ($this->type !== 'digital') {
            throw new ValidationException('Only digital product type is supported.');
        }

        if ($this->media === []) {
            throw new ValidationException('Product media must contain at least one item.');
        }

        if (count($this->media) > 5) {
            throw new ValidationException('Product media can contain at most 5 items.');
        }

        foreach ($this->media as $index => $item) {
            if (!is_array($item)) {
                throw new ValidationException('Product media item ' . ($index + 1) . ' must be an array.');
            }

            if (($item['type'] ?? null) !== 'image') {
                throw new ValidationException('Product media item ' . ($index + 1) . ' must have type image.');
            }

            if (!isset($item['url']) || !is_string($item['url']) || trim($item['url']) === '') {
                throw new ValidationException('Product media item ' . ($index + 1) . ' must have a valid url.');
            }

            if (!array_key_exists('placement', $item)) {
                throw new ValidationException('Product media item ' . ($index + 1) . ' must have placement.');
            }
        }

        if (trim($this->currency) === '') {
            throw new ValidationException('Product currency must not be empty.');
        }

        if ((string) $this->price === '' || !is_numeric($this->price)) {
            throw new ValidationException('Product price must be numeric.');
        }

        if ((float) $this->price < 0) {
            throw new ValidationException('Product price must not be negative.');
        }

        if (!in_array($this->shippingPayer, ['sellerPays', 'buyerPays'], true)) {
            throw new ValidationException('Shipping payer must be sellerPays or buyerPays.');
        }
    }
}
