<?php

declare(strict_types=1);

namespace Shopier\Webhook;

final class WebhookVerifier
{
    public static function verify(string $rawPayload, string $signature, string $token): bool
    {
        if ($signature === '' || $token === '') {
            return false;
        }

        $normalizedSignature = self::normalizeSignature($signature);
        $expectedSignature = hash_hmac('sha256', $rawPayload, $token);

        return hash_equals($expectedSignature, $normalizedSignature);
    }

    private static function normalizeSignature(string $signature): string
    {
        $signature = trim($signature);

        if (str_starts_with($signature, 'sha256=')) {
            return substr($signature, 7);
        }

        return $signature;
    }
}
