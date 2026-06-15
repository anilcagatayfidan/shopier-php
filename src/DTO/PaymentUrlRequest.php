<?php

declare(strict_types=1);

namespace Shopier\DTO;

use Shopier\Exception\ValidationException;

final class PaymentUrlRequest
{
    public function __construct(
        public readonly ProductCreateRequest $productCreateRequest,
        public readonly Customer $customer,
        public readonly int $quantity = 1
    ) {
        if ($quantity < 1) {
            throw new ValidationException('Quantity must be greater than zero.');
        }
    }
}
