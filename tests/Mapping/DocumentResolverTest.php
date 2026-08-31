<?php

namespace Tests\Mapping;

use GuzzleHttp\Psr7\Response;
use OxygenSuite\AadeMyData\Api\ProviderException;
use OxygenSuite\AadeMyData\Exceptions\MarkNotFoundException;
use OxygenSuite\AadeMyData\Mapping\DocumentResolver;
use Tests\TestCase;

class DocumentResolverTest extends TestCase
{
    public function test_resolves_marks_to_ulids_and_memoizes(): void
    {
        $resolver = new DocumentResolver($this->providerClient([
            new Response(200, [], '{"data":[{"id":"01AAA"}]}'),
            new Response(200, [], '{"data":[{"id":"01BBB"}]}'),
        ]));

        $this->assertSame(['01AAA', '01BBB', '01AAA'], $resolver->resolve([400001, '400002', 400001]));
        $this->assertCount(2, $this->history);
        $this->assertSame('mark=400001', $this->history[0]['request']->getUri()->getQuery());
        $this->assertSame('mark=400002', $this->history[1]['request']->getUri()->getQuery());
    }

    public function test_empty_input_makes_no_requests(): void
    {
        $resolver = new DocumentResolver($this->providerClient([]));

        $this->assertSame([], $resolver->resolve(null));
        $this->assertSame([], $resolver->resolve([]));
        $this->assertCount(0, $this->history);
    }

    public function test_unknown_mark_throws(): void
    {
        $resolver = new DocumentResolver($this->providerClient([new Response(200, [], '{"data":[]}')]));

        $this->expectException(MarkNotFoundException::class);
        $this->expectExceptionMessage('Invoice with mark 400009 was not found in the provider.');
        $resolver->resolve(['400009']);
    }

    public function test_failed_lookup_throws_provider_exception_with_http_status(): void
    {
        $resolver = new DocumentResolver($this->providerClient([new Response(503, [], 'down')]));

        try {
            $resolver->resolveOne('400001');
            $this->fail('expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame(503, $e->getCode());
        }
    }
}
