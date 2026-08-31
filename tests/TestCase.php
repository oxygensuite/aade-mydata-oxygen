<?php

namespace Tests;

use Closure;
use Firebed\AadeMyData\Http\Gateway;
use Firebed\AadeMyData\Http\MyDataRequest;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use OxygenSuite\AadeMyData\Api\ProviderClient;
use OxygenSuite\AadeMyData\OxygenGateway;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected const SANDBOX = 'https://sandbox-api.mydataprovider.gr/v2';

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface, response: ?\Psr\Http\Message\ResponseInterface, options: array}> */
    protected array $history = [];

    protected function setUp(): void
    {
        // AADE credentials for the requests that fall through to the package's own gateway.
        MyDataRequest::init('aade-user', 'aade-key', 'dev');
    }

    protected function tearDown(): void
    {
        MyDataRequest::setGateway(null);
    }

    /**
     * The production ProviderClient wiring over an HTTP layer that replays $responses
     * (Guzzle responses or exceptions) and records every request into $this->history.
     */
    protected function providerClient(array $responses, ?Closure $baseUrl = null): ProviderClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return ProviderClient::create('test-token', $baseUrl ?? static fn (): string => self::SANDBOX, ['handler' => $stack]);
    }

    /**
     * Installs an OxygenGateway over providerClient($responses) as the package's gateway.
     */
    protected function registerGateway(array $responses, ?Gateway $inner = null): OxygenGateway
    {
        $gateway = new OxygenGateway($this->providerClient($responses), $inner);
        MyDataRequest::setGateway($gateway);

        return $gateway;
    }

    protected function requestJson(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }
}
