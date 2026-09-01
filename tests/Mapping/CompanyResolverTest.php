<?php

namespace Tests\Mapping;

use GuzzleHttp\Psr7\Response;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Mapping\CompanyResolver;
use Tests\TestCase;

class CompanyResolverTest extends TestCase
{
    public function test_the_vat_number_is_read_from_the_company_profile_once(): void
    {
        $resolver = new CompanyResolver($this->providerClient([new Response(200, [], '{"id":"01C","vat_number":"123456789","name":"Test"}')]));

        $this->assertSame('123456789', $resolver->vatNumber());
        $this->assertSame('123456789', $resolver->vatNumber());

        $this->assertCount(1, $this->history);
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/company', (string) $this->history[0]['request']->getUri());
    }

    public function test_a_failed_lookup_carries_the_status(): void
    {
        $resolver = new CompanyResolver($this->providerClient([new Response(500, [], '{"message":"boom"}')]));

        try {
            $resolver->vatNumber();
            $this->fail('expected a ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(500, $e->getCode());
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }
    }

    public function test_a_profile_without_a_vat_number_is_a_failure_not_an_empty_payload(): void
    {
        $resolver = new CompanyResolver($this->providerClient([new Response(200, [], '{"id":"01C","name":"Test"}')]));

        $this->expectException(ProviderException::class);
        $resolver->vatNumber();
    }

    public function test_a_rejected_token_stays_an_unauthorized_exception(): void
    {
        $resolver = new CompanyResolver($this->providerClient([new Response(401, [], '{"message":"Unauthenticated."}')]));

        $this->expectException(UnauthorizedException::class);
        $resolver->vatNumber();
    }
}
