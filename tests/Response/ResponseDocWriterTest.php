<?php

namespace Tests\Response;

use Firebed\AadeMyData\Models\Error;
use Firebed\AadeMyData\Models\Errors;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use Firebed\AadeMyData\Xml\ResponseDocReader;
use OxygenSuite\AadeMyData\Response\ResponseDocWriter;
use Tests\TestCase;

class ResponseDocWriterTest extends TestCase
{
    public function test_writes_a_response_doc_the_package_can_parse(): void
    {
        $doc = (new ResponseDoc())
            ->add(Response::make(['index' => 1, 'invoiceUid' => 'UID1', 'invoiceMark' => '400001', 'authenticationCode' => 'AUTH', 'qrUrl' => 'https://iview.test/x?a=1&b=2', 'statusCode' => 'Success']))
            ->add(Response::make(['index' => 2, 'invoiceUid' => 'UID2', 'invoiceMark' => null, 'qrUrl' => 'https://iview.test/y', 'statusCode' => 'Success']))
            ->add(Response::make(['index' => 3, 'statusCode' => 'ValidationError', 'errors' => (new Errors())
                ->add(Error::make(['message' => 'header.series: required', 'code' => '422']))
                ->add(Error::make(['message' => 'duplicate', 'code' => '228'])), ]))
            ->add(Response::make(['index' => 4, 'statusCode' => 'TechnicalError', 'errors' => (new Errors())->add(Error::make(['message' => 'connection refused', 'code' => '0']))]));

        $xml = (new ResponseDocWriter())->asXml($doc);

        $this->assertStringStartsWith('<?xml version="1.0" encoding="utf-8"?>', $xml);
        $this->assertStringContainsString('<ResponseDoc xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">', $xml);
        $this->assertStringContainsString('https://iview.test/x?a=1&amp;b=2', $xml);

        $parsed = (new ResponseDocReader())->parseXML($xml);
        $this->assertCount(4, $parsed);

        $first = $parsed[0];
        $this->assertSame(1, $first->getIndex());
        $this->assertTrue($first->isSuccessful());
        $this->assertSame('UID1', $first->getInvoiceUid());
        $this->assertSame('400001', $first->getInvoiceMark());
        $this->assertSame('AUTH', $first->getAuthenticationCode());
        $this->assertSame('https://iview.test/x?a=1&b=2', $first->getQrUrl());
        $this->assertFalse($first->hasErrors());

        $pending = $parsed[1];
        $this->assertTrue($pending->isSuccessful());
        $this->assertNull($pending->getInvoiceMark());
        $this->assertSame('UID2', $pending->getInvoiceUid());

        $invalid = $parsed[2];
        $this->assertSame(3, $invalid->getIndex());
        $this->assertSame('ValidationError', $invalid->getStatusCode());
        $this->assertCount(2, $invalid->getErrors());
        $this->assertSame('422', $invalid->getErrors()[0]->getCode());
        $this->assertSame('header.series: required', $invalid->getErrors()[0]->getMessage());
        $this->assertSame('228', $invalid->getErrors()[1]->getCode());

        $failed = $parsed[3];
        $this->assertSame('TechnicalError', $failed->getStatusCode());
        $this->assertSame('0', $failed->getErrors()[0]->getCode());
        $this->assertSame('connection refused', $failed->getErrors()[0]->getMessage());
    }

    public function test_unset_index_is_omitted(): void
    {
        $xml = (new ResponseDocWriter())->asXml((new ResponseDoc())->add(Response::make(['index' => null, 'cancellationMark' => '400002', 'statusCode' => 'Success'])));

        $this->assertStringNotContainsString('<index>', $xml);
        $response = (new ResponseDocReader())->parseXML($xml)->first();
        $this->assertSame('400002', $response->getCancellationMark());
        $this->assertTrue($response->isSuccessful());
    }

    public function test_empty_doc_is_a_bare_root(): void
    {
        $xml = (new ResponseDocWriter())->asXml(new ResponseDoc());

        $this->assertStringContainsString('<ResponseDoc', $xml);
        $this->assertStringNotContainsString('<response>', $xml);
    }
}
