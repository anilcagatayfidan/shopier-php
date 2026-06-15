<?php

declare(strict_types=1);

namespace Shopier\DTO;

final class PaymentUrlResult
{
    public function __construct(
        public readonly Product $product,
        public readonly string $shopName,
        public readonly string $orderId,
        public readonly string $paymentUrl
    ) {
    }
}
