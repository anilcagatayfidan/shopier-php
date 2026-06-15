<?php

declare(strict_types=1);

namespace Shopier\Exception;

/**
 * Thrown when an HTTP request times out (connect or read timeout) or the
 * connection could not be established. Safe to retry for idempotent requests.
 */
class TimeoutException extends ShopierException
{
}
