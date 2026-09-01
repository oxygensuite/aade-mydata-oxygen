<?php

namespace OxygenSuite\AadeMyData\Signatures;

use OxygenSuite\AadeMyData\Api\ProviderResponse;
use RuntimeException;

/**
 * The provider answered and refused to issue, read or cancel a signature.
 *
 * Deliberately not a ProviderException: that one means "could not reach the provider, try
 * again", while this one means "the provider decided". Creating a signature must never be
 * retried blindly — POST /signatures has no idempotency, so a retry mints a second
 * signature — which is exactly the distinction a caller's catch blocks need to keep apart.
 *
 * getCode() is the HTTP status.
 */
final class SignatureException extends RuntimeException
{
    /**
     * @param array<array-key, list<string>> $errors Field name (or relayed myDATA code) => messages, in Greek.
     */
    private function __construct(string $message, int $status, public readonly array $errors)
    {
        parent::__construct($message, $status);
    }

    /**
     * A 4xx/5xx answer. Note that a wrong or missing issuer VAT number is a 403 with no
     * field information, because the provider authorises before it validates.
     */
    public static function rejected(ProviderResponse $response): self
    {
        return new self($response->message() ?? $response->excerpt(), $response->status, $response->errors());
    }

    /**
     * A success the bridge cannot read: not JSON, or a record missing the fields a signature
     * is made of. Worded like the gateway's 9002 so support recognises it.
     */
    public static function unreadable(ProviderResponse $response, ?string $hint = null): self
    {
        $message = 'The provider returned an unreadable response: '.$response->excerpt();

        return new self($hint === null ? $message : $message.' '.$hint, $response->status, []);
    }
}
