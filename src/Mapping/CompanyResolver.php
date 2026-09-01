<?php

namespace OxygenSuite\AadeMyData\Mapping;

use OxygenSuite\AadeMyData\Api\ProviderClient;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;

/**
 * The VAT number of the company the token belongs to.
 *
 * myDATA's entityVatNumber is filled only when a third party transmits, so it is rightly
 * empty for an ERP sending its own documents — while the provider's payment endpoint always
 * wants it, and answers 403 without naming the field when it is missing. Asked once per
 * gateway, and only when the ERP left it out.
 */
final class CompanyResolver
{
    private ?string $vatNumber = null;

    public function __construct(private ProviderClient $client) {}

    /**
     * @throws ProviderException|UnauthorizedException
     */
    public function vatNumber(): string
    {
        if ($this->vatNumber !== null) {
            return $this->vatNumber;
        }

        $response = $this->client->showCompany();

        if (! $response->isSuccessful()) {
            throw new ProviderException(sprintf('Reading the company profile from the provider failed with HTTP %d.', $response->status), $response->status);
        }

        $vatNumber = $response->get('vat_number');

        if (! is_string($vatNumber) || $vatNumber === '') {
            throw new ProviderException('The provider reported no VAT number for the company this token belongs to.', $response->status);
        }

        return $this->vatNumber = $vatNumber;
    }
}
