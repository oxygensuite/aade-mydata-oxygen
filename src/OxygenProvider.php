<?php

namespace OxygenSuite\AadeMyData;

use Firebed\AadeMyData\Http\MyDataRequest;
use InvalidArgumentException;
use OxygenSuite\AadeMyData\Api\ProviderClient;

/**
 * Bootstrap for integrators:
 *
 *     OxygenProvider::register(token: 'company-api-token');
 *
 * From then on SendInvoices and CancelInvoice go through the Oxygen provider while
 * every other package request keeps talking to AADE.
 */
final class OxygenProvider
{
    private static ?string $env = null;

    /**
     * @param string $token The company's provider API token (Bearer).
     * @param string|null $env 'prod' or 'dev'; when null, follows MyDataRequest::setEnvironment() at request time.
     *
     * @throws InvalidArgumentException when $env is neither 'prod' nor 'dev'
     */
    public static function register(string $token, ?string $env = null): void
    {
        // Anything unrecognised used to mean the sandbox, so a typo ('production', 'live')
        // transmitted real invoices to it and answered Success with a sandbox mark.
        if ($env !== null && ! in_array(strtolower($env), ['prod', 'dev'], true)) {
            throw new InvalidArgumentException(sprintf("Unknown provider environment '%s': use 'prod' or 'dev', or omit it to follow MyDataRequest.", $env));
        }

        self::$env = $env;

        // The base URL is resolved per request so register() may run before MyDataRequest::init().
        $client = ProviderClient::create($token, static fn (): string => self::baseUrl());

        MyDataRequest::setGateway(new OxygenGateway($client));
    }

    public static function unregister(): void
    {
        MyDataRequest::setGateway(null);
    }

    public static function isRegistered(): bool
    {
        return MyDataRequest::gateway() instanceof OxygenGateway;
    }

    public static function baseUrl(): string
    {
        $production = self::$env !== null ? strtolower(self::$env) === 'prod' : MyDataRequest::isProduction();

        return $production ? Endpoints::PRODUCTION : Endpoints::SANDBOX;
    }
}
