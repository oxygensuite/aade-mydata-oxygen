<?php

namespace Tests\Response;

use Firebed\AadeMyData\Models\Response;
use OxygenSuite\AadeMyData\Api\UnauthorizedException;
use OxygenSuite\AadeMyData\Response\ProviderResponseMapper;
use OxygenSuite\AadeMyData\Response\ResponseBatch;
use Tests\TestCase;

class ResponseBatchTest extends TestCase
{
    private ResponseBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->batch = new ResponseBatch(new ProviderResponseMapper());
    }

    public function test_entries_are_numbered_in_submission_order(): void
    {
        $doc = $this->batch->collect(['a', 'b', 'c'], fn (string $item): Response => Response::make(['invoiceUid' => $item, 'statusCode' => 'Success']));

        $this->assertCount(3, $doc);
        $this->assertSame([1, 2, 3], array_map(fn (Response $r) => $r->getIndex(), $doc->all()));
        $this->assertSame(['a', 'b', 'c'], array_map(fn (Response $r) => $r->getInvoiceUid(), $doc->all()));
    }

    public function test_nothing_to_send_is_an_empty_document(): void
    {
        $doc = $this->batch->collect([], fn (string $item): Response => $this->fail('nothing should have been sent'));

        $this->assertCount(0, $doc);
    }

    /**
     * Nothing is registered yet, so the ERP is better served by the exception than by a
     * document full of failures.
     */
    public function test_a_token_rejected_on_the_first_entity_is_thrown(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->batch->collect(['a'], fn (string $item): Response => throw new UnauthorizedException('the token was revoked'));
    }

    /**
     * The entities before the rejection are registered at the provider and legally issued:
     * losing their marks would be worse than reporting the rest as failures.
     */
    public function test_a_token_rejected_mid_batch_keeps_the_marks_already_earned(): void
    {
        $sent = 0;

        $doc = $this->batch->collect(['a', 'b', 'c'], function (string $item) use (&$sent): Response {
            $sent++;

            return $item === 'a'
                ? Response::make(['invoiceMark' => '400001', 'statusCode' => 'Success'])
                : throw new UnauthorizedException('the token was revoked');
        });

        $this->assertCount(3, $doc);
        $this->assertSame('400001', $doc[0]->getInvoiceMark());

        foreach ([$doc[1], $doc[2]] as $failed) {
            $this->assertSame('TechnicalError', $failed->getStatusCode());
            $this->assertSame('401', $failed->getErrors()->first()->getCode());
            $this->assertSame('the token was revoked', $failed->getErrors()->first()->getMessage());
        }

        // Once the token is known to be rejected the provider is not asked again.
        $this->assertSame(2, $sent);
        $this->assertSame([1, 2, 3], array_map(fn (Response $r) => $r->getIndex(), $doc->all()));
    }
}
