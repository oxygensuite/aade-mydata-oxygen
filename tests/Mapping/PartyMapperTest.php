<?php

namespace Tests\Mapping;

use Firebed\AadeMyData\Enums\CountryCode;
use Firebed\AadeMyData\Models\Address;
use Firebed\AadeMyData\Models\Counterpart;
use Firebed\AadeMyData\Models\Issuer;
use Firebed\AadeMyData\Models\Party;
use OxygenSuite\AadeMyData\Mapping\PartyMapper;
use Tests\TestCase;

class PartyMapperTest extends TestCase
{
    public function test_issuer_keeps_only_vat_and_branch(): void
    {
        $issuer = (new Issuer())->setVatNumber('123456789')->setCountry(CountryCode::GR)->setBranch(2)->setName('ACME');

        $this->assertSame(['vat_number' => '123456789', 'branch_code' => 2], (new PartyMapper())->issuer($issuer));
    }

    /**
     * The provider defaults a missing issuer branch to 0 itself, so the bridge leaves it out
     * instead of asserting a branch the ERP never set.
     */
    public function test_an_issuer_branch_the_erp_left_out_is_omitted(): void
    {
        $this->assertSame(['vat_number' => '123456789'], (new PartyMapper())->issuer((new Issuer())->setVatNumber('123456789')));
    }

    public function test_counterpart_is_fully_mapped(): void
    {
        $counterpart = (new Counterpart())
            ->setVatNumber('987654321')->setCountry('GR')->setBranch(0)->setName('Customer')
            ->setAddress((new Address())->setStreet('Ermou')->setNumber('1')->setPostalCode('10563')->setCity('Athens'))
            ->setDocumentIdNo('AB123')->setCountryDocumentId(CountryCode::DE)->setSupplyAccountNo('SUP-1');

        $this->assertSame([
            'vat_number' => '987654321',
            'country_code' => 'GR',
            'branch_code' => 0,
            'name' => 'Customer',
            'address' => ['street' => 'Ermou', 'number' => '1', 'postal_code' => '10563', 'city' => 'Athens'],
            'document_id_no' => 'AB123',
            'document_country_code' => 'DE',
            'supply_account_no' => 'SUP-1',
        ], (new PartyMapper())->counterpart($counterpart));
    }

    public function test_party_omits_missing_fields(): void
    {
        $party = (new Party())->setVatNumber('111111111')->setCountry('GR');

        $this->assertSame(['vat_number' => '111111111', 'country_code' => 'GR'], (new PartyMapper())->party($party));
    }
}
