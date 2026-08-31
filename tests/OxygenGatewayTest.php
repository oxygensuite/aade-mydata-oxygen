<?php

namespace Tests;

use Firebed\AadeMyData\Enums\IncomeClassificationCategory;
use Firebed\AadeMyData\Enums\IncomeClassificationType;
use Firebed\AadeMyData\Enums\UnitMeasurement;
use Firebed\AadeMyData\Enums\VatCategory;
use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Http\CancelDeliveryNote;
use Firebed\AadeMyData\Http\MyDataRequest;
use Firebed\AadeMyData\Http\RequestVatInfo;
use Firebed\AadeMyData\Http\SendInvoices;
use Firebed\AadeMyData\Models\InvoiceDetails;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Tests\Fixtures\Invoices;
use Tests\Support\RecordingGateway;

class OxygenGatewayTest extends TestCase
{
    public function test_send_invoices_posts_each_invoice_and_returns_a_response_doc(): void
    {
        $this->registerGateway([
            new Response(201, [], '{"id":"01A","uid":"UID1","mark":400001,"authentication_code":"AUTH1","icode":"i","url":"https://iview.test/1"}'),
            new Response(202, [], '{"id":"01B","uid":"UID2","icode":"i","url":"https://iview.test/2","transmission_failure":2,"message":"queued"}'),
        ]);

        $request = new SendInvoices();
        $doc = $request->handle([Invoices::b2b(aa: '1'), Invoices::b2b(aa: '2')]);

        $this->assertCount(2, $doc);
        $this->assertSame(1, $doc[0]->getIndex());
        $this->assertTrue($doc[0]->isSuccessful());
        $this->assertSame('400001', $doc[0]->getInvoiceMark());
        $this->assertSame('UID1', $doc[0]->getInvoiceUid());
        $this->assertSame('AUTH1', $doc[0]->getAuthenticationCode());
        $this->assertSame('https://iview.test/1', $doc[0]->getQrUrl());

        $this->assertSame(2, $doc[1]->getIndex());
        $this->assertTrue($doc[1]->isSuccessful());
        $this->assertNull($doc[1]->getInvoiceMark());
        $this->assertSame('UID2', $doc[1]->getInvoiceUid());

        $this->assertCount(2, $this->history);
        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/invoices', (string) $this->history[0]['request']->getUri());
        $this->assertSame('Bearer test-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $json = $this->requestJson(0);
        $this->assertSame('1', $json['header']['number']);
        $this->assertSame('2026-08-27T00:00:00+03:00', $json['header']['issued_at']);
        $this->assertSame('123456789', $json['issuer']['vat_number']);
        $this->assertSame('2', $this->requestJson(1)['header']['number']);

        $this->assertStringContainsString('<InvoicesDoc', $request->getRequestXml());
        $this->assertStringContainsString('<invoiceMark>400001</invoiceMark>', $request->getResponseXML());
    }

    public function test_transport_failure_becomes_a_technical_error_and_the_batch_continues(): void
    {
        $this->registerGateway([
            new ConnectException('cURL error 7: failed to connect', new Request('POST', 'x'), null, ['errno' => 7]),
            new Response(201, [], '{"uid":"UID2","mark":400002,"url":"u"}'),
        ]);

        $doc = (new SendInvoices())->handle([Invoices::b2b(aa: '1'), Invoices::b2b(aa: '2')]);

        $this->assertSame('TechnicalError', $doc[0]->getStatusCode());
        $this->assertSame('0', $doc[0]->getErrors()->first()->getCode());
        $this->assertStringContainsString('failed to connect', $doc[0]->getErrors()->first()->getMessage());
        $this->assertSame('400002', $doc[1]->getInvoiceMark());
    }

    public function test_provider_validation_errors_are_relayed(): void
    {
        $this->registerGateway([new Response(422, [], '{"message":"The given data was invalid.","errors":{"header.issued_at":["The issue date must be today."]}}')]);

        $response = (new SendInvoices())->handle(Invoices::b2b())->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('422', $response->getErrors()->first()->getCode());
        $this->assertSame('header.issued_at: The issue date must be today.', $response->getErrors()->first()->getMessage());
    }

    public function test_unauthorized_throws_the_package_authentication_exception(): void
    {
        $this->registerGateway([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->expectException(MyDataAuthenticationException::class);
        (new SendInvoices())->handle(Invoices::b2b());
    }

    /**
     * A token revoked mid-batch used to abort the loop, so invoices already registered at
     * the provider were legally issued but their marks never reached the ERP.
     */
    public function test_a_token_revoked_mid_batch_keeps_the_marks_already_earned(): void
    {
        $this->registerGateway([
            new Response(201, [], '{"uid":"UID1","mark":400001,"url":"u"}'),
            new Response(401, [], '{"message":"Unauthenticated."}'),
        ]);

        $doc = (new SendInvoices())->handle([Invoices::b2b(aa: '1'), Invoices::b2b(aa: '2'), Invoices::b2b(aa: '3')]);

        // One entry per invoice, in order, so the ERP can still match responses to documents.
        $this->assertCount(3, $doc);
        $this->assertTrue($doc[0]->isSuccessful());
        $this->assertSame('400001', $doc[0]->getInvoiceMark());

        $this->assertSame('TechnicalError', $doc[1]->getStatusCode());
        $this->assertSame('401', $doc[1]->getErrors()->first()->getCode());
        $this->assertSame(2, $doc[1]->getIndex());

        // The third invoice was never sent: no request follows the rejected token.
        $this->assertSame('TechnicalError', $doc[2]->getStatusCode());
        $this->assertSame('401', $doc[2]->getErrors()->first()->getCode());
        $this->assertCount(2, $this->history);
    }

    public function test_duplicate_uid_is_recovered_from_the_stored_invoice(): void
    {
        $uid = str_repeat('A', 40);
        $this->registerGateway([
            new Response(422, [], '{"message":"invalid","errors":{"uid":["The invoice with uid '.$uid.' has already been submitted."]}}'),
            new Response(200, [], '{"data":[{"id":"01A"}]}'),
            new Response(200, [], '{"id":"01A","uid":"'.$uid.'","mark":400001,"authentication_code":"AUTH","url":"https://iview.test/1"}'),
        ]);

        $response = (new SendInvoices())->handle(Invoices::b2b())->first();

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('400001', $response->getInvoiceMark());
        $this->assertSame($uid, $response->getInvoiceUid());
        $this->assertSame('uid='.$uid, $this->history[1]['request']->getUri()->getQuery());
        $this->assertStringEndsWith('/invoices/01A', $this->history[2]['request']->getUri()->getPath());
    }

    public function test_duplicate_uid_that_cannot_be_recovered_stays_a_validation_error(): void
    {
        $this->registerGateway([
            new Response(422, [], '{"message":"invalid","errors":{"uid":["The invoice with uid '.str_repeat('B', 40).' has already been submitted."]}}'),
            new Response(200, [], '{"data":[]}'),
        ]);

        $response = (new SendInvoices())->handle(Invoices::b2b())->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertStringContainsString('already been submitted', $response->getErrors()->first()->getMessage());
    }

    public function test_uid_rejection_without_a_quoted_uid_is_relayed_without_a_lookup(): void
    {
        $this->registerGateway([new Response(422, [], '{"message":"invalid","errors":{"uid":["Duplicate invoice."]}}')]);

        $response = (new SendInvoices())->handle(Invoices::b2b())->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('uid: Duplicate invoice.', $response->getErrors()->first()->getMessage());
        $this->assertCount(1, $this->history);
    }

    public function test_unknown_correlated_mark_is_a_validation_error_without_posting(): void
    {
        $invoice = Invoices::b2b();
        $invoice->getInvoiceHeader()->setCorrelatedInvoices([400009]);
        $this->registerGateway([new Response(200, [], '{"data":[]}')]);

        $response = (new SendInvoices())->handle($invoice)->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('9001', $response->getErrors()->first()->getCode());
        $this->assertCount(1, $this->history);
    }

    public function test_total_cancellation_of_catering_documents_uses_the_cancel_endpoint(): void
    {
        $invoice = Invoices::b2b();
        $invoice->getInvoiceHeader()->setInvoiceType('8.6')->setTotalCancelDeliveryOrders(true)->setMultipleConnectedMarks([400001]);
        $this->registerGateway([
            new Response(200, [], '{"data":[{"id":"01C"}]}'),
            new Response(201, [], '{"uid":"U","mark":400010,"url":"u"}'),
        ]);

        $response = (new SendInvoices())->handle($invoice)->first();

        $this->assertSame('400010', $response->getInvoiceMark());
        $this->assertStringEndsWith('/invoices/cancel', $this->history[1]['request']->getUri()->getPath());
        $this->assertSame(['01C'], $this->requestJson(1)['connected_documents']);
        $this->assertTrue($this->requestJson(1)['header']['cancels_delivery_orders']);
    }

    public function test_other_requests_fall_through_to_the_inner_gateway(): void
    {
        $inner = new RecordingGateway('<?xml version="1.0" encoding="utf-8"?><RequestedVatInfo/>');
        $this->registerGateway([], $inner);

        (new RequestVatInfo())->handle('01/01/2024', '31/12/2024');

        $this->assertInstanceOf(RequestVatInfo::class, $inner->request);
        $this->assertSame(['dateFrom' => '01/01/2024', 'dateTo' => '31/12/2024', 'GroupedPerDay' => 'false'], $inner->query);
        $this->assertCount(0, $this->history);
    }

    public function test_cancel_delivery_note_falls_through_to_the_inner_gateway(): void
    {
        MyDataRequest::init('aade-user', 'aade-key', 'dev', true);
        $inner = new RecordingGateway('<?xml version="1.0" encoding="utf-8"?><ResponseDoc><response><cancellationMark>1</cancellationMark><statusCode>Success</statusCode></response></ResponseDoc>');
        $this->registerGateway([], $inner);

        (new CancelDeliveryNote())->handle('400001', '123456789');

        $this->assertInstanceOf(CancelDeliveryNote::class, $inner->request);
        $this->assertSame(['mark' => '400001', 'entityVatNumber' => '123456789'], $inner->query);
        $this->assertCount(0, $this->history);
    }
    public function test_issue_time_set_on_the_header_reaches_the_provider(): void
    {
        $this->registerGateway([new Response(201, [], '{"uid":"U","mark":400001,"url":"u"}')]);
        $invoice = Invoices::b2b();
        $invoice->getInvoiceHeader()->setIssueTime('10:15:00');

        (new SendInvoices())->handle($invoice);

        $this->assertSame('2026-08-27T10:15:00+03:00', $this->requestJson(0)['header']['issued_at']);
    }

    public function test_a_send_invoices_request_without_models_is_rejected(): void
    {
        $gateway = $this->registerGateway([]);

        $this->expectException(LogicException::class);
        $gateway->post(new SendInvoices(), null, '<InvoicesDoc/>');
    }

    public function test_squashed_invoices_are_sent_with_their_detailed_rows(): void
    {
        $this->registerGateway([new Response(201, [], '{"uid":"U","mark":400001,"url":"u"}')]);
        $invoice = Invoices::b2b();
        $invoice->addInvoiceDetails((new InvoiceDetails())->setLineNumber(2)->setItemDescr('Support')->setQuantity(1)->setMeasurementUnit(UnitMeasurement::UNIT_1)
            ->setNetValue(50.0)->setVatCategory(VatCategory::VAT_1)->setVatAmount(12.0)
            ->addIncomeClassification(IncomeClassificationType::E3_561_001, IncomeClassificationCategory::CATEGORY_1_1, 50.0));
        $invoice->squashInvoiceRows();
        $this->assertCount(1, $invoice->getInvoiceDetails());

        (new SendInvoices())->handle($invoice);

        $this->assertSame(['Consulting', 'Support'], array_column($this->requestJson(0)['lines'], 'description'));
        // The ERP's object is left exactly as it was handed over.
        $this->assertTrue($invoice->isSquashed());
        $this->assertCount(1, $invoice->getInvoiceDetails());
    }
}
