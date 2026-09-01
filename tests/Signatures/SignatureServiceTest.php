<?php

namespace Tests\Signatures;

use Firebed\AadeMyData\Exceptions\MyDataAuthenticationException;
use Firebed\AadeMyData\Models\Invoice;
use Firebed\AadeMyData\Models\PaymentMethodDetail;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Enums\NSP;
use OxygenSuite\AadeMyData\Enums\SignatureDuration;
use OxygenSuite\AadeMyData\Mapping\SignatureMapper;
use OxygenSuite\AadeMyData\Signatures\SignatureException;
use OxygenSuite\AadeMyData\Signatures\SignatureService;
use Tests\Fixtures\Invoices;
use Tests\TestCase;

class SignatureServiceTest extends TestCase
{
    private const RECORD = '{"id":"01SIG","uid":"A1B2","mark":400001,"branch_code":1,"invoice_series":"R","invoice_number":"9",'
        .'"invoice_issued_at":"2026-08-27T10:15:00+03:00","invoice_type":"11.1","invoice_net_amount":10,"invoice_vat_amount":2.4,'
        .'"invoice_total_amount":12.4,"payment_amount":12.4,"nsp":{"name":"Viva","value":1},"duration":1,'
        .'"expires_at":"2026-08-29T22:15:00+03:00","expired":false,"seconds_until_expiration":216000,"terminal_id":"TID-1",'
        .'"unsigned_text":"A1B2;400001;20260827101500;1000;240;1240;1240;TID-1","signed_text":"MEUCIQ==","signature_hex":"3045",'
        .'"timestamp":"20260827101500","created_at":"2026-08-27T10:15:00+03:00","cancelled_at":null}';

    public function test_create_posts_the_mapped_payload_and_returns_the_signature(): void
    {
        $signatures = $this->service([new Response(201, [], self::RECORD)]);
        $invoice = Invoices::pos();

        $signature = $signatures->create($invoice, $invoice->getPaymentMethods()->first(), NSP::VIVA, SignatureDuration::HOURS_60);

        $this->assertSame('POST', $this->history[0]['request']->getMethod());
        $this->assertSame('https://sandbox-api.mydataprovider.gr/v2/signatures', (string) $this->history[0]['request']->getUri());
        $this->assertSame('Bearer test-token', $this->history[0]['request']->getHeaderLine('Authorization'));
        $this->assertSame('TID-1', $this->requestJson(0)['terminal_id']);
        $this->assertSame(1, $this->requestJson(0)['nsp']);

        $this->assertSame('01SIG', $signature->id);
        $this->assertSame('A1B2', $signature->uid);
        $this->assertSame('400001', $signature->mark);
        $this->assertSame('MEUCIQ==', $signature->signature);
        $this->assertSame('3045', $signature->signatureHex);
        $this->assertSame('TID-1', $signature->terminalId);
        $this->assertSame(NSP::VIVA, $signature->nsp);
        $this->assertSame(SignatureDuration::HOURS_60, $signature->duration);
        $this->assertSame(12.4, $signature->paymentAmount);
        $this->assertSame('2026-08-27T10:15:00+03:00', $signature->invoiceIssuedAt->format('c'));
        $this->assertSame('2026-08-29T22:15:00+03:00', $signature->expiresAt->format('c'));
        $this->assertFalse($signature->expired);
        $this->assertNull($signature->cancelledAt);
    }

    public function test_find_and_cancel_address_the_signature_by_id(): void
    {
        $cancelled = str_replace('"cancelled_at":null', '"cancelled_at":"2026-08-27T11:00:00+03:00"', self::RECORD);
        $signatures = $this->service([new Response(200, [], self::RECORD), new Response(200, [], $cancelled)]);

        $this->assertNull($signatures->find('01SIG')->cancelledAt);
        $this->assertSame('2026-08-27T11:00:00+03:00', $signatures->cancel('01SIG')->cancelledAt->format('c'));

        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
        $this->assertSame('/v2/signatures/01SIG', $this->history[1]['request']->getUri()->getPath());
    }

    public function test_pending_lists_the_signatures_still_waiting_to_be_used(): void
    {
        $signatures = $this->service([
            new Response(200, [], '{"data":['.self::RECORD.','.self::RECORD.'],"links":{},"meta":{}}'),
            new Response(200, [], '{"data":[],"links":{},"meta":{}}'),
        ]);

        $this->assertCount(2, $signatures->pending());
        $this->assertSame([], $signatures->pending(3));

        $this->assertSame('status=pending&page=1', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame('status=pending&page=3', $this->history[1]['request']->getUri()->getQuery());
    }

    public function test_a_rejection_carries_the_status_and_the_field_errors(): void
    {
        $signatures = $this->service([new Response(422, [], '{"message":"Τα δεδομένα δεν είναι έγκυρα.","errors":{"terminal_id":["Το πεδίο είναι υποχρεωτικό."]}}')]);

        try {
            $signatures->find('01SIG');
            $this->fail('expected a SignatureException');
        } catch (SignatureException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame('Τα δεδομένα δεν είναι έγκυρα.', $e->getMessage());
            $this->assertSame(['terminal_id' => ['Το πεδίο είναι υποχρεωτικό.']], $e->errors);
        }
    }

    /**
     * The provider authorises before it validates, so a wrong issuer VAT number comes back as
     * a 403 with no field information rather than a 422 naming it.
     */
    public function test_a_vat_number_that_is_not_the_token_company_is_a_403(): void
    {
        $invoice = Invoices::pos();
        $signatures = $this->service([new Response(403, [], '{"message":"This action is unauthorized."}')]);

        try {
            $signatures->create($invoice, $invoice->getPaymentMethods()->first(), NSP::VIVA, SignatureDuration::HOURS_2);
            $this->fail('expected a SignatureException');
        } catch (SignatureException $e) {
            $this->assertSame(403, $e->getCode());
            $this->assertSame('This action is unauthorized.', $e->getMessage());
            $this->assertSame([], $e->errors);
        }
    }

    public function test_a_success_that_cannot_be_read_is_not_half_a_signature(): void
    {
        $incomplete = str_replace('"signed_text":"MEUCIQ==",', '', self::RECORD);
        $signatures = $this->service([new Response(201, [], '<html>oops</html>'), new Response(201, [], $incomplete)]);
        $invoice = Invoices::pos();
        $payment = $invoice->getPaymentMethods()->first();

        foreach (['not json', 'missing signed_text'] as $case) {
            try {
                $signatures->create($invoice, $payment, NSP::VIVA, SignatureDuration::HOURS_2);
                $this->fail("expected a SignatureException for $case");
            } catch (SignatureException $e) {
                $this->assertStringContainsString('unreadable response', $e->getMessage());
            }
        }
    }

    /**
     * The POST already minted the signature, so the caller must be told to look it up rather
     * than create a second one — POST /signatures has no idempotency.
     */
    public function test_an_unreadable_create_points_at_the_recovery_path(): void
    {
        $signatures = $this->service([new Response(201, [], '{"id":"01SIG"}')]);
        $invoice = Invoices::pos();

        try {
            $signatures->create($invoice, $invoice->getPaymentMethods()->first(), NSP::VIVA, SignatureDuration::HOURS_2);
            $this->fail('expected a SignatureException');
        } catch (SignatureException $e) {
            $this->assertStringContainsString('pending()', $e->getMessage());
        }
    }

    /**
     * signature_hex is the printable form, not what an invoice references: a record without it
     * is still usable, so it must not cost the ERP a signature it has already been billed for.
     */
    public function test_a_record_without_the_printable_hex_is_still_a_signature(): void
    {
        $withoutHex = str_replace('"signature_hex":"3045",', '', self::RECORD);
        $signatures = $this->service([new Response(200, [], $withoutHex)]);

        $signature = $signatures->find('01SIG');

        $this->assertSame('MEUCIQ==', $signature->signature);
        $this->assertNull($signature->signatureHex);
    }
    public function test_the_token_being_rejected_is_the_package_authentication_exception(): void
    {
        $signatures = $this->service([new Response(401, [], '{"message":"Unauthenticated."}')]);

        $this->expectException(MyDataAuthenticationException::class);
        $signatures->find('01SIG');
    }

    /**
     * A transport failure stays a ProviderException: the signature may or may not have been
     * created, so the caller has to look it up rather than blindly retry.
     */
    public function test_an_unreachable_provider_is_a_transport_failure(): void
    {
        $signatures = $this->service([new ConnectException('Connection refused', new Request('POST', 'signatures'))]);
        $invoice = Invoices::pos();

        $this->expectException(ProviderException::class);
        $signatures->create($invoice, $invoice->getPaymentMethods()->first(), NSP::VIVA, SignatureDuration::HOURS_2);
    }

    public function test_only_what_the_models_hold_is_sent(): void
    {
        $signatures = $this->service([new Response(201, [], self::RECORD)]);

        $signatures->create(new Invoice(), (new PaymentMethodDetail())->setType(7)->setAmount(5.5)->setTid('T-9'), NSP::WEB_ECR, SignatureDuration::HOURS_2);

        $this->assertSame(['nsp' => 2, 'payment_amount' => 5.5, 'duration' => 2, 'terminal_id' => 'T-9'], $this->requestJson(0));
    }

    private function service(array $responses): SignatureService
    {
        return new SignatureService($this->providerClient($responses), new SignatureMapper());
    }
}
