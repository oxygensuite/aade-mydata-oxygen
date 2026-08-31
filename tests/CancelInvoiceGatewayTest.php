<?php

namespace Tests;

use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Http\CancelInvoice;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

class CancelInvoiceGatewayTest extends TestCase
{
    public function test_cancels_a_delivery_note_by_mark(): void
    {
        $this->registerGateway([
            new Response(200, [], '{"data":[{"id":"01A"}]}'),
            new Response(200, [], '{"cancellation_mark":400002,"cancelled_at":"2026-08-27T10:16:00+03:00"}'),
        ]);

        $request = new CancelInvoice();
        $doc = $request->handle('400001', '123456789');

        $this->assertCount(1, $doc);
        $this->assertTrue($doc->first()->isSuccessful());
        $this->assertSame('400002', $doc->first()->getCancellationMark());
        $this->assertNull($doc->first()->getIndex());
        $this->assertSame('mark=400001', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
        $this->assertStringEndsWith('/invoices/01A/cancel', $this->history[1]['request']->getUri()->getPath());
        $this->assertStringContainsString('<cancellationMark>400002</cancellationMark>', $request->getResponseXML());
    }

    public function test_non_delivery_notes_are_rejected_by_the_provider_as_validation_errors(): void
    {
        $this->registerGateway([
            new Response(200, [], '{"data":[{"id":"01A"}]}'),
            new Response(403, [], '{"message":"Only delivery notes (9.3) can be cancelled."}'),
        ]);

        $response = (new CancelInvoice())->handle('400001')->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('403', $response->getErrors()->first()->getCode());
        $this->assertSame('Only delivery notes (9.3) can be cancelled.', $response->getErrors()->first()->getMessage());
    }

    public function test_unknown_mark_is_a_9001_validation_error(): void
    {
        $this->registerGateway([new Response(200, [], '{"data":[]}')]);

        $response = (new CancelInvoice())->handle('400009')->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('9001', $response->getErrors()->first()->getCode());
        $this->assertSame('Invoice with mark 400009 was not found in the provider.', $response->getErrors()->first()->getMessage());
        $this->assertCount(1, $this->history);
    }

    public function test_missing_mark_is_a_9001_validation_error_without_a_request(): void
    {
        $this->registerGateway([]);

        $response = (new CancelInvoice())->handle('')->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('9001', $response->getErrors()->first()->getCode());
        $this->assertCount(0, $this->history);
    }

    public function test_failed_lookup_is_a_technical_error(): void
    {
        $this->registerGateway([new Response(500, [], '{"message":"Server Error"}')]);

        $response = (new CancelInvoice())->handle('400001')->first();

        $this->assertSame('TechnicalError', $response->getStatusCode());
        $this->assertSame('500', $response->getErrors()->first()->getCode());
    }

    public function test_transport_failure_is_a_technical_error(): void
    {
        $this->registerGateway([new ConnectException('cURL error 28: timed out', new Request('GET', 'x'), null, ['errno' => 28])]);

        $response = (new CancelInvoice())->handle('400001')->first();

        $this->assertSame('TechnicalError', $response->getStatusCode());
        $this->assertSame('28', $response->getErrors()->first()->getCode());
    }

    public function test_unauthorized_throws(): void
    {
        $this->registerGateway([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->expectException(MyDataAuthenticationException::class);
        (new CancelInvoice())->handle('400001');
    }
}
