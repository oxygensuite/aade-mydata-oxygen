<?php

namespace Tests;

use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Http\SendPaymentsMethod;
use Firebed\AadeMyData\Models\PaymentMethod;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LogicException;
use Tests\Fixtures\Invoices;

class SendPaymentsGatewayTest extends TestCase
{
    private const FOUND = '{"data":[{"id":"01ABC"}]}';

    private const COMPANY = '{"id":"01C","vat_number":"123456789"}';

    private const STORED = '{"invoice_mark":400001,"payment_method_mark":500001,"invoice_total":12.4,"total_paid_amount":12.4,"total_unpaid_amount":0}';

    public function test_a_payment_is_posted_to_the_invoice_the_mark_names(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(201, [], self::STORED),
        ]);

        $request = new SendPaymentsMethod();
        $doc = $request->handle(Invoices::posPayment());

        $this->assertCount(1, $doc);
        $this->assertSame(1, $doc[0]->getIndex());
        $this->assertTrue($doc[0]->isSuccessful());
        $this->assertSame('400001', $doc[0]->getInvoiceMark());
        $this->assertSame('500001', $doc[0]->getPaymentMethodMark());

        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/invoices?mark=400001', (string) $this->history[0]['request']->getUri());
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/company', (string) $this->history[1]['request']->getUri());
        $this->assertSame('POST', $this->history[2]['request']->getMethod());
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/invoices/01ABC/payments', (string) $this->history[2]['request']->getUri());
        $this->assertSame([
            'issuer_vat_number' => '123456789',
            'payments' => [['type' => 7, 'amount' => 12.4, 'transaction_id' => 'TX-1', 'signature' => 'SIGNED']],
        ], $this->requestJson(2));

        $this->assertStringContainsString('<PaymentMethodsDoc', $request->getRequestXml());
        $this->assertStringContainsString('<paymentMethodMark>500001</paymentMethodMark>', $request->getResponseXML());
    }

    public function test_the_entity_vat_number_is_used_when_the_erp_set_one(): void
    {
        $this->registerGateway([new Response(200, [], self::FOUND), new Response(201, [], self::STORED)]);

        $payment = Invoices::posPayment()->setEntityVatNumber('999888777');
        (new SendPaymentsMethod())->handle($payment);

        $this->assertCount(2, $this->history);
        $this->assertSame('999888777', $this->requestJson(1)['issuer_vat_number']);
    }

    public function test_an_empty_entity_vat_number_falls_back_to_the_company(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(201, [], self::STORED),
        ]);

        (new SendPaymentsMethod())->handle(Invoices::posPayment()->setEntityVatNumber(''));

        $this->assertSame('123456789', $this->requestJson(2)['issuer_vat_number']);
    }

    /**
     * One company lookup per gateway, not per payment.
     */
    public function test_the_company_is_looked_up_once_for_the_whole_batch(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(201, [], self::STORED),
            new Response(201, [], self::STORED),
        ]);

        (new SendPaymentsMethod())->handle([Invoices::posPayment(), Invoices::posPayment()]);

        // mark lookup, company, two payments — the mark is memoized too.
        $this->assertCount(4, $this->history);
        $this->assertSame('/v2/invoices/01ABC/payments', $this->history[3]['request']->getUri()->getPath());
    }

    /**
     * The payment is stored; only its myDATA transmission is queued.
     */
    public function test_a_queued_transmission_is_successful_without_a_payment_mark(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(202, [], '{"invoice_mark":400001,"payment_method_mark":null,"invoice_total":12.4}'),
        ]);

        $response = (new SendPaymentsMethod())->handle(Invoices::posPayment())->first();

        $this->assertTrue($response->isSuccessful());
        $this->assertSame('400001', $response->getInvoiceMark());
        $this->assertNull($response->getPaymentMethodMark());
    }

    public function test_provider_validation_errors_are_relayed(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(422, [], '{"message":"invalid","errors":{"payments.0.signature":["The signature is invalid."]}}'),
        ]);

        $response = (new SendPaymentsMethod())->handle(Invoices::posPayment())->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('payments.0.signature: The signature is invalid.', $response->getErrors()->first()->getMessage());
    }

    public function test_the_provider_being_unavailable_is_a_technical_error(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(503, [], '{"message":"Service unavailable. Please try again later."}'),
        ]);

        $response = (new SendPaymentsMethod())->handle(Invoices::posPayment())->first();

        $this->assertSame('TechnicalError', $response->getStatusCode());
        $this->assertSame('503', $response->getErrors()->first()->getCode());
    }

    public function test_a_payment_without_an_invoice_mark_is_rejected_without_asking_the_provider(): void
    {
        $this->registerGateway([]);

        $payment = (new PaymentMethod())->addPaymentMethodDetails((new PaymentMethodDetail())->setType(7)->setAmount(12.4));
        $response = (new SendPaymentsMethod())->handle($payment)->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('9001', $response->getErrors()->first()->getCode());
        $this->assertCount(0, $this->history);
    }

    /**
     * A document transmitted through the ERP channel before the switch cannot be paid here.
     */
    public function test_a_mark_the_provider_does_not_know_is_a_validation_error(): void
    {
        $this->registerGateway([new Response(200, [], '{"data":[]}')]);

        $response = (new SendPaymentsMethod())->handle(Invoices::posPayment(400999))->first();

        $this->assertSame('ValidationError', $response->getStatusCode());
        $this->assertSame('9001', $response->getErrors()->first()->getCode());
        $this->assertStringContainsString('400999', $response->getErrors()->first()->getMessage());
    }

    public function test_a_failed_company_lookup_is_a_technical_error_and_the_batch_continues(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(500, [], '{"message":"boom"}'),
            new Response(200, [], self::COMPANY),
            new Response(201, [], self::STORED),
        ]);

        $doc = (new SendPaymentsMethod())->handle([Invoices::posPayment(), Invoices::posPayment()]);

        $this->assertCount(2, $doc);
        $this->assertSame('TechnicalError', $doc[0]->getStatusCode());
        $this->assertSame('500', $doc[0]->getErrors()->first()->getCode());
        $this->assertTrue($doc[1]->isSuccessful());
    }

    public function test_a_transport_failure_becomes_a_technical_error_and_the_batch_continues(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new ConnectException('Connection refused', new Request('POST', 'payments')),
            new Response(201, [], self::STORED),
        ]);

        $doc = (new SendPaymentsMethod())->handle([Invoices::posPayment(), Invoices::posPayment()]);

        $this->assertSame('TechnicalError', $doc[0]->getStatusCode());
        $this->assertSame('0', $doc[0]->getErrors()->first()->getCode());
        $this->assertTrue($doc[1]->isSuccessful());
    }

    public function test_a_token_revoked_mid_batch_keeps_the_marks_already_earned(): void
    {
        $this->registerGateway([
            new Response(200, [], self::FOUND),
            new Response(200, [], self::COMPANY),
            new Response(201, [], self::STORED),
            new Response(401, [], '{"message":"Unauthenticated."}'),
        ]);

        $doc = (new SendPaymentsMethod())->handle([Invoices::posPayment(), Invoices::posPayment(), Invoices::posPayment()]);

        $this->assertCount(3, $doc);
        $this->assertSame('500001', $doc[0]->getPaymentMethodMark());
        $this->assertSame('TechnicalError', $doc[1]->getStatusCode());
        $this->assertSame('401', $doc[1]->getErrors()->first()->getCode());
        $this->assertSame('TechnicalError', $doc[2]->getStatusCode());
    }

    public function test_a_token_rejected_on_the_first_payment_throws(): void
    {
        $this->registerGateway([new Response(200, [], self::FOUND), new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->expectException(MyDataAuthenticationException::class);
        (new SendPaymentsMethod())->handle(Invoices::posPayment());
    }

    public function test_a_send_payments_method_request_without_models_is_rejected(): void
    {
        $gateway = $this->registerGateway([]);

        $this->expectException(LogicException::class);
        $gateway->post(new SendPaymentsMethod(), null, '<PaymentMethodsDoc/>');
    }
}
