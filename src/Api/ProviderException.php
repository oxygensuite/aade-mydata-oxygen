<?php

namespace OxygenSuite\AadeMyData\Api;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;

/**
 * The provider could not be reached or refused the request at the HTTP level.
 */
class ProviderException extends RuntimeException
{
    public const CONNECTION_FAILED = 0;
    public const TIMED_OUT = 28;

    public static function fromGuzzle(GuzzleException $exception): self
    {
        // Both carry the cURL errno; a timeout surfaces as either depending on where it hit.
        $context = $exception instanceof ConnectException || $exception instanceof RequestException
            ? $exception->getHandlerContext()
            : [];

        $code = ($context['errno'] ?? null) === self::TIMED_OUT ? self::TIMED_OUT : self::CONNECTION_FAILED;

        return new self($exception->getMessage(), $code, $exception);
    }
}
