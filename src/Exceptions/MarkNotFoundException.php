<?php

namespace OxygenSuite\AadeMyData\Exceptions;

use RuntimeException;

/**
 * A myDATA mark referenced by the invoice (correlated / connected document, or the
 * invoice to cancel) is unknown to the provider — typically because it was
 * transmitted through the ERP channel before the switch to the provider.
 */
final class MarkNotFoundException extends RuntimeException
{
    public function __construct(public readonly string $mark)
    {
        parent::__construct(sprintf('Invoice with mark %s was not found in the provider.', $mark));
    }
}
