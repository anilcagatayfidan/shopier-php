<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\DTO\Customer;

final class TokenCalculatorPayload
{
    public function __construct(private readonly Customer $customer)
    {
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function toString(): string
    {
        return '[' . $this->customer->email . ']'
            . '[' . $this->customer->countryCodeDigits() . ']'
            . '[' . $this->customer->phoneDigits() . ']'
            . '[' . $this->customer->firstName . ']'
            . '[' . $this->customer->lastName . ']'
            . '[' . $this->customer->country . ']';
    }
}
