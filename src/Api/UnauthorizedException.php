<?php

namespace OxygenSuite\AadeMyData\Api;

use RuntimeException;

/**
 * The provider rejected the bearer token (HTTP 401).
 *
 * Deliberately not a ProviderException: a bad token aborts the whole batch instead
 * of becoming a per-invoice TechnicalError entry.
 */
class UnauthorizedException extends RuntimeException
{
    public function __construct(string $message = 'The provider rejected the API token.')
    {
        parent::__construct($message, 401);
    }
}
