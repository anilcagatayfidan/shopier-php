<?php

declare(strict_types=1);

namespace Shopier\Checkout;

use Shopier\Exception\HtmlParsingException;

final class HtmlParser
{
    public function extractShopName(string $html): string
    {
        $patterns = [
            '/check_payment_progress\/([^"\'\s<>&?\/]+)/i',
            '/\/s\/shipping\/([^"\'\s<>&?\/]+)/i',
            '/\/s\/payment\/([^"\'\s<>&?\/]+)/i',
            '/data-shop-name=["\']([^"\']+)["\']/i',
            '/shopName\s*[:=]\s*["\']([^"\']+)["\']/i',
            '/shop_name\s*[:=]\s*["\']([^"\']+)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1 && trim($matches[1]) !== '') {
                return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        throw new HtmlParsingException('Shop name could not be extracted from HTML.');
    }

    public function extractCsrfToken(string $html): string
    {
        $patterns = [
            '/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i',
            '/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']csrf-token["\'][^>]*>/i',
            '/<input[^>]+name=["\']_token["\'][^>]+value=["\']([^"\']+)["\'][^>]*>/i',
            '/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']_token["\'][^>]*>/i',
            '/csrfToken\s*[:=]\s*["\']([^"\']+)["\']/i',
            '/csrf_token\s*[:=]\s*["\']([^"\']+)["\']/i',
            '/x-csrf-token\s*[:=]\s*["\']([^"\']+)["\']/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1 && trim($matches[1]) !== '') {
                return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
        }

        throw new HtmlParsingException('CSRF token could not be extracted from HTML.');
    }
}
