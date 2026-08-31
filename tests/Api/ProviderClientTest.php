<?php

namespace Tests\Api;

use Composer\InstalledVersions;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use Tests\TestCase;

class ProviderClientTest extends TestCase
{
    public function test_store_invoice_posts_json(): void
    {
        $client = $this->providerClient([new Response(201, [], '{"id":"01ABC"}')]);

        $response = $client->storeInvoice(['header' => ['series' => 'A']]);

        $this->assertSame(201, $response->status);
        $this->assertSame('01ABC', $response->get('id'));
        $request = $this->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/invoices', (string) $request->getUri());
        $this->assertSame('Bearer test-token', $request->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertSame('{"header":{"series":"A"}}', (string) $request->getBody());
    }

    public function test_other_endpoints(): void
    {
        $client = $this->providerClient([new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(200, [], '{}'), new Response(201, [], '{}')]);

        $client->findInvoices(['mark' => '400001']);
        $client->showInvoice('01ABC');
        $client->cancelInvoice('01ABC');
        $client->cancelCateringDocuments(['x' => 1]);

        $this->assertSame('GET https://sandbox-api.mydataprovider.gr/v2/invoices?mark=400001', $this->describe(0));
        $this->assertSame('GET https://sandbox-api.mydataprovider.gr/v2/invoices/01ABC', $this->describe(1));
        $this->assertSame('PATCH https://sandbox-api.mydataprovider.gr/v2/invoices/01ABC/cancel', $this->describe(2));
        $this->assertSame('POST https://sandbox-api.mydataprovider.gr/v2/invoices/cancel', $this->describe(3));
    }

    public function test_base_url_may_be_resolved_lazily(): void
    {
        $base = 'https://sandbox-api.mydataprovider.gr/v2';
        $client = $this->providerClient([new Response(200, [], '{}'), new Response(200, [], '{}')], function () use (&$base) {
            return $base;
        });

        $client->showInvoice('a');
        $base = 'https://api.mydataprovider.gr/v2/';
        $client->showInvoice('b');

        $this->assertSame('GET https://sandbox-api.mydataprovider.gr/v2/invoices/a', $this->describe(0));
        $this->assertSame('GET https://api.mydataprovider.gr/v2/invoices/b', $this->describe(1));
    }

    public function test_http_errors_are_returned_not_thrown(): void
    {
        $client = $this->providerClient([new Response(422, [], '{"message":"invalid","errors":{"a":["b"]}}')]);

        $response = $client->storeInvoice([]);

        $this->assertSame(422, $response->status);
        $this->assertSame(['a' => ['b']], $response->errors());
    }

    public function test_401_throws_unauthorized(): void
    {
        $client = $this->providerClient([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->expectException(UnauthorizedException::class);
        $client->storeInvoice([]);
    }

    public function test_transport_failures_are_wrapped(): void
    {
        $client = $this->providerClient([new ConnectException('cURL error 28: timeout', new Request('POST', 'x'), null, ['errno' => 28])]);

        try {
            $client->storeInvoice([]);
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderException::TIMED_OUT, $e->getCode());
            $this->assertStringContainsString('timeout', $e->getMessage());
        }
    }

    public function test_connection_failures_have_code_zero(): void
    {
        $client = $this->providerClient([new ConnectException('cURL error 6: could not resolve', new Request('POST', 'x'), null, ['errno' => 6])]);

        try {
            $client->storeInvoice([]);
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(ProviderException::CONNECTION_FAILED, $e->getCode());
        }
    }

    public function test_create_sends_the_token_and_uses_the_provider_timeouts(): void
    {
        $client = $this->providerClient([new Response(200, [], '{}')]);

        $client->showInvoice('01ABC');

        $options = $this->history[0]['options'];
        $this->assertSame(5, $options['connect_timeout']);
        $this->assertSame(10, $options['timeout']);
        $this->assertFalse($options['http_errors']);
        $this->assertSame('Bearer test-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('application/json', $this->history[0]['request']->getHeaderLine('Accept'));
    }

    /**
     * The bearer token says which company is transmitting, not which software: the provider
     * needs to tell bridge traffic apart from a direct API integration.
     */
    public function test_requests_identify_the_bridge_and_its_version(): void
    {
        $client = $this->providerClient([new Response(200, [], '{}')]);

        $client->showInvoice('01ABC');

        $header = $this->history[0]['request']->getHeaderLine('X-Client');

        $this->assertStringStartsWith('oxygensuite/aade-mydata-oxygen/', $header);
        $this->assertStringEndsWith(InstalledVersions::getPrettyVersion('oxygensuite/aade-mydata-oxygen'), $header);
    }

    private function describe(int $i): string
    {
        $request = $this->history[$i]['request'];

        return $request->getMethod().' '.$request->getUri();
    }
}
