<?php

namespace Tests\Support;

use Firebed\AadeMyData\Http\Gateway;
use Firebed\AadeMyData\Http\MyDataRequest;

final class RecordingGateway implements Gateway
{
    public ?MyDataRequest $request = null;
    public ?array $query = null;
    public ?string $body = null;

    public function __construct(private string $responseXml = '<?xml version="1.0" encoding="utf-8"?><ResponseDoc><response><statusCode>Success</statusCode></response></ResponseDoc>') {}

    public function get(MyDataRequest $request, array $query): string
    {
        return $this->record($request, $query, null);
    }

    public function post(MyDataRequest $request, ?array $query = null, ?string $body = null): string
    {
        return $this->record($request, $query, $body);
    }

    private function record(MyDataRequest $request, ?array $query, ?string $body): string
    {
        $this->request = $request;
        $this->query = $query;
        $this->body = $body;

        return $this->responseXml;
    }
}
