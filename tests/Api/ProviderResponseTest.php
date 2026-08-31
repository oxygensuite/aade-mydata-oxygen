<?php

namespace Tests\Api;

use OxygenSuite\AadeMyData\Api\ProviderResponse;
use Tests\TestCase;

class ProviderResponseTest extends TestCase
{
    public function test_decodes_json_body(): void
    {
        $response = new ProviderResponse(201, '{"id":"01ABC","mark":400001,"message":"ok","errors":{"uid":["dup"]}}');

        $this->assertTrue($response->isJson());
        $this->assertTrue($response->isSuccessful());
        $this->assertSame('01ABC', $response->get('id'));
        $this->assertSame(400001, $response->get('mark'));
        $this->assertNull($response->get('missing'));
        $this->assertSame('ok', $response->message());
        $this->assertSame(['uid' => ['dup']], $response->errors());
    }

    public function test_non_json_body(): void
    {
        $response = new ProviderResponse(502, '<html>Bad gateway</html>');

        $this->assertFalse($response->isJson());
        $this->assertFalse($response->isSuccessful());
        $this->assertNull($response->body);
        $this->assertNull($response->message());
        $this->assertSame([], $response->errors());
        $this->assertSame('<html>Bad gateway</html>', $response->rawBody);
    }

    public function test_first_id_reads_paginated_data(): void
    {
        $this->assertSame('01ABC', (new ProviderResponse(200, '{"data":[{"id":"01ABC"},{"id":"01DEF"}]}'))->firstId());
        $this->assertNull((new ProviderResponse(200, '{"data":[]}'))->firstId());
        $this->assertNull((new ProviderResponse(200, 'nope'))->firstId());
    }
}
