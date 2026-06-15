<?php

declare(strict_types=1);

namespace Shopier\DTO;

final class Order
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $status,
        public readonly ?string $paymentStatus,
        public readonly ?bool $installments,
        public readonly ?string $paymentMethod,
        public readonly ?string $currency,
        public readonly ?string $dateCreated,
        public readonly array $totals = [],
        public readonly array $discounts = [],
        public readonly array $shippingInfo = [],
        public readonly array $billingInfo = [],
        public readonly ?string $note = null,
        public readonly array $lineItems = [],
        public readonly array $fulfillments = [],
        public readonly array $returns = [],
        public readonly array $refunds = [],
        public readonly array $data = []
    ) {
    }

    public static function fromArray(array $data): self
    {
        if (isset($data['data']) && is_array($data['data'])) {
            $data = $data['data'];
        }

        $id = $data['id'] ?? $data['orderId'] ?? $data['order_id'] ?? '';
        $status = $data['status'] ?? null;
        $paymentStatus = $data['paymentStatus'] ?? $data['payment_status'] ?? null;
        $installments = $data['installments'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? $data['payment_method'] ?? null;
        $currency = $data['currency'] ?? null;
        $dateCreated = $data['dateCreated'] ?? $data['date_created'] ?? null;
        $note = $data['note'] ?? null;

        return new self(
            (string) $id,
            is_string($status) ? $status : null,
            is_string($paymentStatus) ? $paymentStatus : null,
            is_bool($installments) ? $installments : null,
            is_string($paymentMethod) ? $paymentMethod : null,
            is_string($currency) ? $currency : null,
            is_string($dateCreated) ? $dateCreated : null,
            is_array($data['totals'] ?? null) ? $data['totals'] : [],
            is_array($data['discounts'] ?? null) ? $data['discounts'] : [],
            is_array($data['shippingInfo'] ?? null) ? $data['shippingInfo'] : [],
            is_array($data['billingInfo'] ?? null) ? $data['billingInfo'] : [],
            is_string($note) ? $note : null,
            is_array($data['lineItems'] ?? null) ? $data['lineItems'] : [],
            is_array($data['fulfillments'] ?? null) ? $data['fulfillments'] : [],
            is_array($data['returns'] ?? null) ? $data['returns'] : [],
            is_array($data['refunds'] ?? null) ? $data['refunds'] : [],
            $data
        );
    }

    public function isPaid(): bool
    {
        return $this->paymentStatus === 'paid';
    }

    public function isFulfilled(): bool
    {
        return $this->status === 'fulfilled';
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
