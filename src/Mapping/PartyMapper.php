<?php

namespace OxygenSuite\AadeMyData\Mapping;

use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\Party;

/**
 * Every method accepts null and answers [] for it, which Values::compact() drops.
 */
final class PartyMapper
{
    /**
     * The provider fills the issuer's name and address from the company profile, and defaults
     * a missing branch to 0 itself, so neither is asserted here.
     *
     * @return array<array-key, mixed>
     */
    public function issuer(?Issuer $issuer): array
    {
        if ($issuer === null) {
            return [];
        }

        return Values::compact([
            'vat_number' => $issuer->getVatNumber(),
            'branch_code' => $issuer->getBranch(),
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function counterpart(?Counterpart $counterpart): array
    {
        if ($counterpart === null) {
            return [];
        }

        return Values::compact($this->party($counterpart) + [
            'document_id_no' => $counterpart->getDocumentIdNo(),
            'document_country_code' => Values::scalar($counterpart->getCountryDocumentId()),
            'supply_account_no' => $counterpart->getSupplyAccountNo(),
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function party(?Party $party): array
    {
        if ($party === null) {
            return [];
        }

        return Values::compact([
            'vat_number' => $party->getVatNumber(),
            'country_code' => Values::scalar($party->getCountry()),
            'branch_code' => $party->getBranch(),
            'name' => $party->getName(),
            'address' => $this->address($party->getAddress()),
        ]);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function address(?Address $address): array
    {
        if ($address === null) {
            return [];
        }

        return Values::compact([
            'street' => $address->getStreet(),
            'number' => $address->getNumber(),
            'postal_code' => $address->getPostalCode(),
            'city' => $address->getCity(),
        ]);
    }
}
