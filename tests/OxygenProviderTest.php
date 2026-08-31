<?php

namespace Tests;

use Firebed\AadeMyData\Http\GuzzleGateway;
use Firebed\AadeMyData\Http\MyDataRequest;
use InvalidArgumentException;
use OxygenSuite\AadeMyData\Endpoints;
use OxygenSuite\AadeMyData\OxygenGateway;
use OxygenSuite\AadeMyData\OxygenProvider;

class OxygenProviderTest extends TestCase
{
    public function test_register_installs_the_gateway_and_unregister_restores_the_default(): void
    {
        $this->assertFalse(OxygenProvider::isRegistered());

        OxygenProvider::register('token');
        $this->assertInstanceOf(OxygenGateway::class, MyDataRequest::gateway());
        $this->assertTrue(OxygenProvider::isRegistered());

        OxygenProvider::unregister();
        $this->assertInstanceOf(GuzzleGateway::class, MyDataRequest::gateway());
        $this->assertFalse(OxygenProvider::isRegistered());
    }

    public function test_base_url_follows_the_package_environment_unless_env_is_given(): void
    {
        OxygenProvider::register('token');
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2', OxygenProvider::baseUrl());

        MyDataRequest::setEnvironment('prod');
        $this->assertSame('https://api.mydataprovider.gr/v2', OxygenProvider::baseUrl());

        OxygenProvider::register('token', env: 'dev');
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2', OxygenProvider::baseUrl());

        OxygenProvider::register('token', env: 'PROD');
        $this->assertSame('https://api.mydataprovider.gr/v2', OxygenProvider::baseUrl());
    }

    /**
     * An unrecognised environment used to fall through to the sandbox, so real invoices
     * were transmitted to it and came back Success with a sandbox mark.
     */
    public function test_an_unknown_environment_is_rejected_instead_of_falling_back_to_the_sandbox(): void
    {
        foreach (['production', 'live', 'sandbox', 'prod ', ''] as $env) {
            try {
                OxygenProvider::register('token', env: $env);
                $this->fail("expected '$env' to be rejected");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("'prod'", $e->getMessage());
            }
        }

        $this->assertFalse(OxygenProvider::isRegistered());
    }

    public function test_both_environments_are_accepted_in_any_case(): void
    {
        foreach (['prod' => Endpoints::PRODUCTION, 'PROD' => Endpoints::PRODUCTION, 'dev' => Endpoints::SANDBOX, 'Dev' => Endpoints::SANDBOX] as $env => $expected) {
            OxygenProvider::register('token', env: $env);
            $this->assertSame($expected, OxygenProvider::baseUrl());
        }
    }
}
