<?php

declare(strict_types=1);

namespace Shopier\DTO;

use Shopier\Exception\ValidationException;

final class Customer
{
    public function __construct(
        public readonly string $email,
        public readonly string $phoneCountryCode,
        public readonly string $phoneWithoutCountryNumber,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $country
    ) {
        $this->validate();
    }

    public function validate(): void
    {
        if (filter_var($this->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('Customer email must be valid.');
        }

        if (trim($this->phoneCountryCode) === '') {
            throw new ValidationException('Customer phone country code must not be empty.');
        }

        if ($this->phoneDigits() === '') {
            throw new ValidationException('Customer phone number must not be empty.');
        }

        if (trim($this->firstName) === '') {
            throw new ValidationException('Customer first name must not be empty.');
        }

        if (trim($this->lastName) === '') {
            throw new ValidationException('Customer last name must not be empty.');
        }

        if (trim($this->country) === '') {
            throw new ValidationException('Customer country must not be empty.');
        }
    }

    /**
     * Localized country names as Shopier's (Turkish) storefront expects them in
     * the shipment form's `country` field. Falls back to the raw value for codes
     * not listed here.
     */
    private const COUNTRY_NAMES = [
        'TR' => 'Türkiye',
        'US' => 'Amerika Birleşik Devletleri',
        'DE' => 'Almanya',
        'GB' => 'Birleşik Krallık',
        'NL' => 'Hollanda',
        'FR' => 'Fransa',
        'AT' => 'Avusturya',
        'BE' => 'Belçika',
        'CH' => 'İsviçre',
        'AZ' => 'Azerbaycan',
    ];

    public function normalizedCountryCode(): string
    {
        $digits = preg_replace('/\D+/', '', $this->phoneCountryCode) ?? '';

        return '+' . $digits;
    }

    /**
     * ISO country code used by the storefront phone-country selector
     * (`phone-contact-select`), e.g. "TR".
     */
    public function countryCode(): string
    {
        return strtoupper(trim($this->country));
    }

    /**
     * Localized country name used in the storefront `country` field, e.g. "Türkiye".
     */
    public function countryDisplayName(): string
    {
        return self::COUNTRY_NAMES[$this->countryCode()] ?? $this->country;
    }

    /**
     * National phone number with the storefront's display grouping (no country
     * code), e.g. "555 555 55 55" for TR. Falls back to the raw digits.
     */
    public function nationalPhoneFormatted(): string
    {
        $digits = $this->phoneDigits();

        if ($this->countryCodeDigits() === '90' && strlen($digits) === 10) {
            return substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
        }

        return $digits;
    }

    public function countryCodeDigits(): string
    {
        return preg_replace('/\D+/', '', $this->phoneCountryCode) ?? '';
    }

    public function phoneDigits(): string
    {
        return preg_replace('/\D+/', '', $this->phoneWithoutCountryNumber) ?? '';
    }

    public function formattedPhone(): string
    {
        $digits = $this->phoneDigits();

        if ($this->countryCodeDigits() === '90' && strlen($digits) === 10) {
            return '+90 ' . substr($digits, 0, 3) . ' ' . substr($digits, 3, 3) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
        }

        return $this->normalizedCountryCode() . ' ' . $digits;
    }
}
