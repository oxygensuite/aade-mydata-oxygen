<?php

namespace Tests\Response;

use Firebed\AadeMyData\Models\Error;
use Firebed\AadeMyData\Models\Response;
use Firebed\AadeMyData\Models\ResponseDoc;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Api\ProviderResponse;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;
use OxygenSuite\AadeMyData\Response\ProviderResponseMapper;
use OxygenSuite\AadeMyData\Response\ResponseDocWriter;
use Tests\TestCase;

class ProviderResponseMapperTest extends TestCase
{
    private ProviderResponseMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new ProviderResponseMapper();
    }

    public function test_201_is_success_with_mark(): void
    {
        $response = $this->mapper->forStore(new ProviderResponse(201, '{"id":"01A","uid":"UID","mark":400001,"authentication_code":"AUTH","icode":"x","url":"https://iview.test/x"}'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('UID', $response->getInvoiceUid());
        $this->assertSame('400001', $response->getInvoiceMark());
        $this->assertSame('AUTH', $response->getAuthenticationCode());
        $this->assertSame('https://iview.test/x', $response->getQrUrl());
        $this->assertFalse($response->hasErrors());
    }

    public function test_202_is_success_without_mark(): void
    {
        $response = $this->mapper->forStore(new ProviderResponse(202, '{"id":"01A","uid":"UID","icode":"x","url":"https://iview.test/x","transmission_failure":2,"message":"queued"}'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('UID', $response->getInvoiceUid());
        $this->assertNull($response->getInvoiceMark());
        $this->assertSame('https://iview.test/x', $response->getQrUrl());
    }

    public function test_2xx_without_json_is_a_technical_error(): void
    {
        $response = $this->mapper->forStore(new ProviderResponse(201, '<html>oops</html>'));

        $this->assertSame('TechnicalError', $response->getStatusCode());
        $this->assertSame('9002', $response->getErrors()->first()->getCode());
    }

    public function test_422_maps_field_errors_and_relayed_mydata_codes(): void
    {
        $response = $this->mapper->forStore(new ProviderResponse(422, '{"message":"The given data was invalid.","errors":{"header.series":["required","too long"],"301":["Invoice with MARK not found"]}}'));

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame([
            ['422', 'header.series: required'],
            ['422', 'header.series: too long'],
            ['301', 'Invoice with MARK not found'],
        ], $this->errors($response));
    }

    public function test_422_without_errors_uses_the_message(): void
    {
        $response = $this->mapper->forStore(new ProviderResponse(422, '{"message":"nope"}'));

        $this->assertSame([['422', 'nope']], $this->errors($response));
    }

    public function test_403_and_404_are_validation_errors_with_the_provider_message(): void
    {
        $forbidden = $this->mapper->forStore(new ProviderResponse(403, '{"message":"This company is not authorized to create B2B invoices."}'));
        $missing = $this->mapper->forCancel(new ProviderResponse(404, '{"message":"Not found."}'));

        $this->assertSame('ValidationError', $forbidden->getStatusCode());
        $this->assertSame([['403', 'This company is not authorized to create B2B invoices.']], $this->errors($forbidden));
        $this->assertSame([['404', 'Not found.']], $this->errors($missing));
    }

    public function test_423_429_and_5xx_are_technical_errors(): void
    {
        $locked = $this->mapper->forStore(new ProviderResponse(423, '{"message":"Invoice is currently being processed."}'));
        $limited = $this->mapper->forStore(new ProviderResponse(429, '{"message":"Too Many Attempts."}'));
        $down = $this->mapper->forStore(new ProviderResponse(502, '<html>Bad Gateway</html>'));

        $this->assertSame([['423', 'Invoice is currently being processed.']], $this->errors($locked));
        $this->assertSame('TechnicalError', $limited->getStatusCode());
        $this->assertSame('429', $limited->getErrors()->first()->getCode());
        $this->assertSame([['502', '<html>Bad Gateway</html>']], $this->errors($down));
    }

    public function test_transport_failure_and_unknown_or_missing_mark(): void
    {
        $transport = $this->mapper->forTransportFailure(new ProviderException('cURL error 28', ProviderException::TIMED_OUT));
        $unknown = $this->mapper->forMarkNotFound(new MarkNotFoundException('400009'));
        $missing = $this->mapper->forMissingMark('A mark is required to cancel an invoice.');

        $this->assertSame('TechnicalError', $transport->getStatusCode());
        $this->assertSame([['28', 'cURL error 28']], $this->errors($transport));
        $this->assertSame('ValidationError', $unknown->getStatusCode());
        $this->assertSame([['9001', 'Invoice with mark 400009 was not found in the provider.']], $this->errors($unknown));
        $this->assertSame([['9001', 'A mark is required to cancel an invoice.']], $this->errors($missing));
    }

    public function test_payments_201_is_success_with_both_marks(): void
    {
        $response = $this->mapper->forPayments(new ProviderResponse(201, '{"invoice_mark":400001,"payment_method_mark":500001,"invoice_total":12.4,"total_paid_amount":12.4,"total_unpaid_amount":0}'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('400001', $response->getInvoiceMark());
        $this->assertSame('500001', $response->getPaymentMethodMark());
    }

    /**
     * The payment is stored; only its myDATA transmission is queued, so the invoice mark is
     * there and the payment mark is not.
     */
    public function test_payments_202_is_success_without_a_payment_mark(): void
    {
        $response = $this->mapper->forPayments(new ProviderResponse(202, '{"invoice_mark":400001,"payment_method_mark":null,"invoice_total":12.4}'));

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('400001', $response->getInvoiceMark());
        $this->assertNull($response->getPaymentMethodMark());
    }

    public function test_payments_relay_field_errors_and_mydata_codes(): void
    {
        $response = $this->mapper->forPayments(new ProviderResponse(422, '{"message":"invalid","errors":{"payments.0.signature":["The signature is invalid."],"304":["Payment method already submitted"]}}'));

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame([
            ['422', 'payments.0.signature: The signature is invalid.'],
            ['304', 'Payment method already submitted'],
        ], array_map(fn ($e) => [$e->getCode(), $e->getMessage()], $response->getErrors()->all()));
    }

    public function test_payments_service_unavailable_is_a_technical_error(): void
    {
        $response = $this->mapper->forPayments(new ProviderResponse(503, '{"message":"Service unavailable. Please try again later."}'));

        $this->assertSame('TechnicalError', $response->getStatusCode());
        $this->assertSame('503', $response->getErrors()->first()->getCode());
        $this->assertSame('Service unavailable. Please try again later.', $response->getErrors()->first()->getMessage());
    }

    public function test_payments_2xx_without_json_is_a_technical_error(): void
    {
        $response = $this->mapper->forPayments(new ProviderResponse(201, '<html>oops</html>'));

        $this->assertSame('TechnicalError', $response->getStatusCode());
        $this->assertSame('9002', $response->getErrors()->first()->getCode());
    }
    public function test_cancel_success(): void
    {
        $cancel = $this->mapper->forCancel(new ProviderResponse(200, '{"cancellation_mark":400002,"cancelled_at":"2026-08-27T10:00:00+03:00"}'));

        $this->assertTrue($cancel->isSuccessful());
        $this->assertSame('400002', $cancel->getCancellationMark());
    }

    public function test_stored_invoice_answers_a_duplicate_rejection_when_readable(): void
    {
        $rejection = new ProviderResponse(422, '{"message":"dup","errors":{"uid":["already submitted"]}}');

        $stored = $this->mapper->forStoredInvoice(new ProviderResponse(200, '{"id":"01A","uid":"UID","mark":400001,"authentication_code":"AUTH","url":"https://iview.test/x"}'), $rejection);
        $this->assertTrue($stored->isSuccessful());
        $this->assertSame('400001', $stored->getInvoiceMark());
        $this->assertSame('UID', $stored->getInvoiceUid());

        $unreadable = $this->mapper->forStoredInvoice(new ProviderResponse(500, 'down'), $rejection);
        $this->assertSame('ValidationError', $unreadable->getStatusCode());
        $this->assertSame([['422', 'uid: already submitted']], $this->errors($unreadable));
    }

    public function test_duplicate_uid_is_the_one_quoted_in_a_uid_rejection(): void
    {
        $uid = str_repeat('AB', 20);

        $this->assertSame($uid, $this->mapper->duplicateUid(new ProviderResponse(422, '{"errors":{"uid":["The invoice with uid '.$uid.' has already been submitted."]}}')));
        $this->assertNull($this->mapper->duplicateUid(new ProviderResponse(422, '{"errors":{"uid":["Duplicate."]}}')));
        $this->assertNull($this->mapper->duplicateUid(new ProviderResponse(422, '{"errors":{"header.series":["'.$uid.'"]}}')));
        $this->assertNull($this->mapper->duplicateUid(new ProviderResponse(201, '{"uid":"'.$uid.'"}')));
    }

    public function test_index_set_afterwards_still_leads_the_serialized_response(): void
    {
        $response = $this->mapper->forStore(new ProviderResponse(201, '{"uid":"U","mark":1}'))->setIndex(1);

        $xml = (new ResponseDocWriter())->asXml((new ResponseDoc())->add($response));

        $this->assertMatchesRegularExpression('#<index>1</index>\s*<invoiceUid>U</invoiceUid>\s*<invoiceMark>1</invoiceMark>\s*<statusCode>Success</statusCode>#', $xml);
    }

    /**
     * @return array<int, array{0: string, 1: string}> [code, message] pairs
     */
    private function errors(Response $response): array
    {
        return array_map(fn (Error $error) => [$error->getCode(), $error->getMessage()], $response->getErrors()->all());
    }
}
