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
        // Shopier concatenates the buyer fields with no separators, e.g.
        // "anilgfx0@gmail.comTR5555555555AnılFidanTürkiye".
        return $this->customer->email
            . $this->customer->countryCode()
            . $this->customer->phoneDigits()
            . $this->customer->firstName
            . $this->customer->lastName
            . $this->customer->countryDisplayName();
    }
}
